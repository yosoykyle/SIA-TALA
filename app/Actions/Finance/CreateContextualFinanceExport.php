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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateContextualFinanceExport
{
    public const AccountStatusPurpose = 'Accounting account-position review';

    public const VerifiedPaymentsPurpose = 'Accounting verified-payment reconciliation';

    public const VerifiedPaymentsHeaders = [
        'Payment reference', 'Account reference', 'Learner number', 'Learner name', 'Term', 'Entry type',
        'Amount (PHP)', 'Channel', 'Masked external reference', 'Payment or refund at (Asia/Manila)',
        'Verified at (Asia/Manila)', 'Reverses payment reference', 'Verification basis',
    ];

    public const AccountStatusHeaders = [
        'Account reference', 'Learner number', 'Learner name', 'Program', 'Term', 'Assessed charges (PHP)',
        'Verified payments applied (PHP)', 'Approved coverage applied (PHP)', 'Due now (PHP)',
        'Later fees (PHP)', 'Remaining term fees (PHP)', 'Account status', 'Assessment version',
        'Assessment basis', 'Approval reference', 'As of (Asia/Manila)',
    ];

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
        $asOf ??= CarbonImmutable::now(config('app.timezone'));
        $preview = $this->accountStatusPreview($actor, $enrollments, $asOf);

        return $this->persist($actor, FinanceExport::TypeAccountStatus, self::AccountStatusPurpose,
            $preview['headers'], array_map(array_values(...), $preview['rows']), $scope, null, $asOf);
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @return array{headers:list<string>,rows:list<array<string,string|null>>}
     */
    public function accountStatusPreview(User $actor, Collection $enrollments, ?CarbonImmutable $asOf = null): array
    {
        $this->authorize($actor, self::AccountStatusPurpose, $enrollments->count());
        $asOf ??= CarbonImmutable::now(config('app.timezone'));
        $headers = self::AccountStatusHeaders;
        $current = Enrollment::query()->whereKey($enrollments->modelKeys())->with([
            'credentialUser', 'studentProfile.program', 'admissionApplication.program', 'term', 'termAccount.assessments',
        ])->get()->keyBy('id');
        $rows = $enrollments->map(function (Enrollment $requested) use ($actor, $asOf, $headers, $current): array {
            $enrollment = $current->get($requested->id);
            if (! $enrollment instanceof Enrollment) {
                throw ValidationException::withMessages(['export' => 'An account record changed. Refresh the account records before exporting.']);
            }
            Gate::forUser($actor)->authorize('view', $enrollment);
            $account = $enrollment->termAccount;
            if (! $account instanceof TermAccount) {
                throw ValidationException::withMessages(['export' => 'Every Account Status row must have a Term Account.']);
            }
            $assessment = $account->assessments->where('state', Assessment::StateActive)->sortByDesc('version')->first();
            $position = $this->accounts->forAccount($account, $asOf);
            $sources = app(IndividualAssessmentSources::class)->forEnrollment($enrollment);
            $needsReview = $assessment === null || $position['state'] === 'Unavailable'
                || $sources['course_drop'] !== null
                || ($sources['adjustment'] && (int) $assessment->source_proposal_version_id !== (int) $enrollment->current_proposal_version_id);
            $laterCents = collect($position['obligations'])->where('is_due', false)
                ->sum(fn (array $row): int => $this->money->toCents($row['balance']));
            $identity = $this->learnerIdentity($enrollment);

            return array_combine($headers, [
                'TERM-ACCOUNT-'.$account->id,
                $identity['number'],
                $identity['name'],
                $identity['program'],
                $enrollment->term?->label,
                $assessment?->total,
                $needsReview ? null : $position['payment_applied'],
                $needsReview ? null : $position['coverage_applied'],
                $needsReview ? null : $position['current_due'],
                $needsReview ? null : $this->money->fromCents($laterCents),
                $needsReview ? null : $position['remaining_balance'],
                $needsReview ? 'Needs review' : ($this->money->greaterThanZero($position['current_due']) ? 'Payment due' : 'No payment due now'),
                $assessment !== null ? (string) $assessment->version : null,
                $assessment !== null ? Str::headline($assessment->assessment_basis) : null,
                $assessment?->authority_reference,
                $asOf->setTimezone(config('app.display_timezone'))->toRfc3339String(),
            ]);
        })->values()->all();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @param array{state?:?string,from?:?string,until?:?string} $filters */
    public function createVerifiedPayments(User $actor, TermAccount $account, string $purpose, array $filters, ?CarbonImmutable $asOf = null): FinanceExport
    {
        $asOf ??= CarbonImmutable::now(config('app.timezone'));
        $account = $this->currentPaymentAccount($actor, $account);
        $payments = $this->verifiedPaymentsQuery($account, $filters)->limit(10001)->get();
        $this->authorize($actor, self::VerifiedPaymentsPurpose, $payments->count());

        return $this->persist($actor, FinanceExport::TypeVerifiedPayments, self::VerifiedPaymentsPurpose,
            self::VerifiedPaymentsHeaders, array_map(array_values(...), $this->verifiedPaymentRows($account, $payments)), $filters, $account, $asOf);
    }

    /**
     * @param  array{state?:?string,from?:?string,until?:?string}  $filters
     * @return array{headers:list<string>,rows:list<array<string,string|null>>,count:int,identity:array{name:?string,number:?string,program:string,term:?string}}
     */
    public function verifiedPaymentsPreview(User $actor, TermAccount $account, array $filters, ?CarbonImmutable $asOf = null): array
    {
        $account = $this->currentPaymentAccount($actor, $account);
        $query = $this->verifiedPaymentsQuery($account, $filters);

        return ['headers' => self::VerifiedPaymentsHeaders, 'rows' => $this->verifiedPaymentRows($account, (clone $query)->limit(5)->get()),
            'count' => (clone $query)->count(), 'identity' => $this->learnerIdentity($account->enrollment)];
    }

    private function currentPaymentAccount(User $actor, TermAccount $account): TermAccount
    {
        $this->authorize($actor, self::VerifiedPaymentsPurpose, 0);
        $current = TermAccount::query()->with(['enrollment.studentProfile.program', 'enrollment.admissionApplication.program', 'enrollment.credentialUser', 'enrollment.term', 'term'])->find($account->id);
        if ($current === null || $current->enrollment === null) {
            throw ValidationException::withMessages(['export' => 'The account changed. Refresh the account before exporting.']);
        }
        Gate::forUser($actor)->authorize('view', $current->enrollment);

        return $current;
    }

    /** @param array{state?:?string,from?:?string,until?:?string} $filters */
    private function verifiedPaymentsQuery(TermAccount $account, array $filters): Builder
    {
        Validator::make($filters, [
            'state' => ['nullable', 'in:'.Payment::StatePosted.','.Payment::StateReversal],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', ...(filled($filters['from'] ?? null) ? ['after_or_equal:from'] : [])],
        ], [
            'state.in' => 'Choose All entries, Verified payments or Reversals.',
            'from.date_format' => 'Enter a valid Verified from date.',
            'until.date_format' => 'Enter a valid Verified through date.',
            'until.after_or_equal' => 'Choose a Verified through date on or after Verified from.',
        ])->validate();
        $query = Payment::query()->where('term_account_id', $account->id)->whereIn('state', [Payment::StatePosted, Payment::StateReversal])->with('term');
        $query->when($filters['state'] ?? null, fn (Builder $query, string $state) => $query->where('state', $state));
        $query->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('verified_at', '>=', CarbonImmutable::parse($from, 'Asia/Manila')->startOfDay()->utc()));
        $query->when($filters['until'] ?? null, fn (Builder $query, string $until) => $query->where('verified_at', '<=', CarbonImmutable::parse($until, 'Asia/Manila')->endOfDay()->utc()));

        return $query->orderBy('verified_at')->orderBy('id');
    }

    /** @return array{name:?string,number:?string,program:string,term:?string} */
    private function learnerIdentity(Enrollment $enrollment): array
    {
        $profile = $enrollment->studentProfile;
        $application = $enrollment->admissionApplication;
        $program = $profile?->program ?? $application?->program;
        $name = collect([$profile?->first_name, $profile?->middle_name, $profile?->last_name])->filter()->implode(' ');
        if (blank($name)) {
            $name = collect([$application?->first_name, $application?->middle_name, $application?->last_name, $application?->extension_name])->filter()->implode(' ');
        }

        return ['name' => filled($name) ? $name : $enrollment->credentialUser?->name,
            'number' => filled($profile?->student_number) ? $profile->student_number : $application?->application_reference,
            'program' => collect([$program?->code, $program?->name])->filter()->implode(' — '), 'term' => $enrollment->term?->label];
    }

    /** @param Collection<int,Payment> $payments @return list<array<string,string|null>> */
    private function verifiedPaymentRows(TermAccount $account, Collection $payments): array
    {
        $identity = $this->learnerIdentity($account->enrollment);
        $originals = Payment::query()->where('term_account_id', $account->id)->whereKey($payments->pluck('reverses_payment_id')->filter())->get()->keyBy('id');

        return $payments->map(function (Payment $payment) use ($account, $identity, $originals): array {
            $original = $originals->get($payment->reverses_payment_id);
            $paidAt = $payment->state === Payment::StateReversal ? ($payment->external_refund_at ?? $payment->paid_at) : $payment->paid_at;

            return array_combine(self::VerifiedPaymentsHeaders, [
                $payment->provider_reference ?? 'PAYMENT-'.$payment->id, 'TERM-ACCOUNT-'.$account->id,
                $identity['number'], $identity['name'], $payment->term?->label ?? $account->term?->label,
                $payment->state === Payment::StateReversal ? 'Reversal' : 'Verified payment',
                $this->money->normalize($payment->amount), $payment->channelLabel(),
                $this->mask($payment->external_check_reference ?? $payment->provider_reference),
                $paidAt?->setTimezone('Asia/Manila')->toRfc3339String(),
                $payment->verified_at?->setTimezone('Asia/Manila')->toRfc3339String(),
                $original !== null ? ($original->provider_reference ?? 'PAYMENT-'.$original->id) : null,
                filled($payment->verification_basis) ? Str::headline($payment->verification_basis) : null,
            ]);
        })->all();
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
