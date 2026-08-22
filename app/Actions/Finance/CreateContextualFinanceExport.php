<?php

namespace App\Actions\Finance;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FinanceExport;
use App\Models\Payment;
use App\Models\TermAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateContextualFinanceExport
{
    public function __construct(
        private readonly TermAccountProjection $accounts,
        private readonly DecimalMoney $money,
    ) {}

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  array<string, mixed>  $scope
     */
    public function createAccountStatus(User $actor, string $purpose, Collection $enrollments, array $scope, ?CarbonImmutable $asOf = null): FinanceExport
    {
        $this->authorize($actor, $purpose, $enrollments->count());
        $asOf ??= CarbonImmutable::now(config('app.timezone'));
        $headers = [
            'Account Reference', 'Person Reference', 'Program', 'Term', 'Assessment Total (PHP)',
            'Required Now (PHP)', 'Verified Payment Applied (PHP)', 'Approved Coverage Applied (PHP)',
            'Current Due (PHP)', 'Projection State', 'Satisfaction Basis', 'Assessment Basis',
            'Source Version or Authority Reference', 'As of (Asia/Manila)',
        ];
        $rows = $enrollments->map(function (Enrollment $enrollment) use ($asOf): array {
            $enrollment->loadMissing(['credentialUser', 'studentProfile.program', 'admissionApplication.program', 'term', 'termAccount.assessments.obligations']);
            $account = $enrollment->termAccount;
            if (! $account instanceof TermAccount) {
                throw ValidationException::withMessages(['export' => 'Every Account Status row must have a Term Account.']);
            }
            $assessment = $account->assessments->where('state', Assessment::StateActive)->sortByDesc('version')->first();
            $position = $this->accounts->forAccount($account, $asOf);
            $requiredNow = $assessment?->obligations
                ->filter(fn ($obligation): bool => $obligation->due_at !== null && $obligation->due_at->lessThanOrEqualTo($asOf))
                ->sum(fn ($obligation): int => $this->money->toCents($obligation->amount)) ?? 0;
            $satisfaction = match (true) {
                $position['state'] !== 'Cleared' => 'None',
                $this->money->isZeroOrNegative($assessment->total ?? 0) => 'NoPaymentRequired',
                $this->money->greaterThanZero($position['payment_applied']) && $this->money->greaterThanZero($position['coverage_applied']) => 'Mixed',
                $this->money->greaterThanZero($position['payment_applied']) => 'VerifiedPayment',
                $this->money->greaterThanZero($position['coverage_applied']) => 'ApprovedCoverage',
                default => 'None',
            };
            $profile = $enrollment->studentProfile;
            $program = $profile->program ?? $enrollment->admissionApplication->program ?? null;

            return [
                'TERM-ACCOUNT-'.$account->id,
                $profile->student_number ?? $enrollment->admissionApplication->application_reference ?? $enrollment->credentialUser->email ?? null,
                collect([$program?->code, $program?->name])->filter()->implode(' — '),
                $enrollment->term?->label,
                $assessment?->total,
                $this->money->fromCents($requiredNow),
                $position['payment_applied'],
                $position['coverage_applied'],
                $position['current_due'],
                $position['state'],
                $satisfaction,
                $assessment?->assessment_basis,
                $assessment->authority_reference ?? ($assessment?->fee_plan_id !== null ? 'FEE-PLAN-'.$assessment->fee_plan_id.'-V'.$assessment->version : null),
                $asOf->setTimezone(config('app.display_timezone'))->toRfc3339String(),
            ];
        })->values()->all();

        return $this->persist($actor, FinanceExport::TypeAccountStatus, $purpose, $headers, $rows, $scope, null, $asOf);
    }

    /** @param array{state?:?string,from?:?string,until?:?string} $filters */
    public function createVerifiedPayments(User $actor, TermAccount $account, string $purpose, array $filters, ?CarbonImmutable $asOf = null): FinanceExport
    {
        $asOf ??= CarbonImmutable::now(config('app.timezone'));
        $state = $filters['state'] ?? null;
        if ($state !== null && ! in_array($state, [Payment::StatePosted, Payment::StateReversal], true)) {
            throw ValidationException::withMessages(['state' => 'Select a supported current payment state.']);
        }
        $account->loadMissing(['enrollment.studentProfile', 'enrollment.credentialUser', 'term']);
        $query = Payment::query()->where('term_account_id', $account->id)->with('term');
        $query->when($state, fn ($query, string $state) => $query->where('state', $state));
        $query->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('verified_at', '>=', CarbonImmutable::parse($from, config('app.display_timezone'))->startOfDay()->utc()));
        $query->when($filters['until'] ?? null, fn ($query, string $until) => $query->where('verified_at', '<=', CarbonImmutable::parse($until, config('app.display_timezone'))->endOfDay()->utc()));
        $payments = $query->orderBy('verified_at')->orderBy('id')->limit(10001)->get();
        $this->authorize($actor, $purpose, $payments->count());
        $headers = [
            'Payment Reference', 'Account Reference', 'Person Reference', 'Term', 'Amount (PHP)',
            'Channel', 'Masked External Reference', 'Posted At (Asia/Manila)', 'Verification Basis', 'Current State',
        ];
        $rows = $payments->map(function (Payment $payment) use ($account): array {
            $enrollment = $account->enrollment;
            $external = $payment->external_check_reference ?? $payment->provider_reference;

            return [
                $payment->provider_reference ?? 'PAYMENT-'.$payment->id,
                'TERM-ACCOUNT-'.$payment->term_account_id,
                $enrollment->studentProfile->student_number ?? $enrollment->credentialUser->email ?? null,
                $payment->term->label ?? $account->term->label,
                $this->money->normalize($payment->amount),
                $payment->channelLabel(),
                $this->mask($external),
                $payment->verified_at?->setTimezone(config('app.display_timezone'))->toRfc3339String(),
                $payment->verification_basis,
                $payment->state,
            ];
        })->all();

        return $this->persist($actor, FinanceExport::TypeVerifiedPayments, $purpose, $headers, $rows, $filters, $account, $asOf);
    }

    private function authorize(User $actor, string $purpose, int $rowCount): void
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may create a contextual finance export.');
        }
        if (blank($purpose) || $rowCount > 10000) {
            throw ValidationException::withMessages(['export' => 'A contextual export requires a purpose and no more than 10,000 rows.']);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     * @param  array<string, mixed>  $scope
     */
    private function persist(User $actor, string $type, string $purpose, array $headers, array $rows, array $scope, ?TermAccount $account, CarbonImmutable $asOf): FinanceExport
    {
        $export = FinanceExport::query()->create([
            'reference' => 'FIN-'.Str::upper(Str::random(16)), 'type' => $type, 'term_account_id' => $account?->id,
            'initiated_by' => $actor->id, 'purpose' => trim($purpose), 'normalized_scope' => $scope,
            'row_count' => count($rows), 'outcome' => $rows === [] ? FinanceExport::OutcomeNoRows : FinanceExport::OutcomePreparing,
        ]);
        if ($rows === []) {
            return $export;
        }

        $path = 'finance-exports/'.$export->reference.'.csv';
        try {
            $contents = $this->csv($headers, $rows);
            if (! Storage::disk('local')->put($path, $contents)) {
                throw ValidationException::withMessages(['export' => 'The export could not be stored.']);
            }
            $export->update([
                'outcome' => FinanceExport::OutcomeGenerated, 'disk' => 'local', 'path' => $path,
                'checksum' => hash('sha256', $contents), 'generated_at' => $asOf->utc(),
            ]);

            return $export->refresh();
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            $export->update(['outcome' => FinanceExport::OutcomeFailed, 'disk' => null, 'path' => null, 'checksum' => null, 'generated_at' => null]);
            throw $exception;
        }
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw ValidationException::withMessages(['export' => 'The export could not be prepared.']);
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_map($this->protect(...), $headers), ',', '"', '\\', "\r\n");
        foreach ($rows as $row) {
            fputcsv($stream, array_map($this->protect(...), $row), ',', '"', '\\', "\r\n");
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw ValidationException::withMessages(['export' => 'The export could not be completed.']);
        }

        return $contents;
    }

    private function protect(mixed $value): string
    {
        $string = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $string) === 1 ? "'{$string}" : $string;
    }

    private function mask(?string $reference): ?string
    {
        return blank($reference) ? null : '••••'.substr((string) $reference, -4);
    }
}
