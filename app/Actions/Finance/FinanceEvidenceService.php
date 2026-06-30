<?php

namespace App\Actions\Finance;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FinancialAccommodation;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinanceEvidenceService
{
    public const EVIDENCE_DISCLAIMER = 'This document is for internal billing verification only and is not an official tax receipt.';

    public const OutputSoa = 'SOA';

    public const OutputBillingSlip = 'BILLING_SLIP';

    public const OutputPaymentAcknowledgement = 'PAYMENT_ACKNOWLEDGEMENT';

    public const ActionView = 'VIEW';

    public const ActionPrint = 'PRINT';

    public const CopyStudent = 'STUDENT_COPY';

    public const CopyAccounting = 'ACCOUNTING_COPY';

    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @return array<string, mixed>
     */
    public function studentFinance(User $actor): array
    {
        $profile = StudentProfile::query()
            ->with(['user', 'program'])
            ->where('user_id', $actor->id)
            ->first();

        if (! $profile instanceof StudentProfile) {
            return $this->unavailable('No student profile is linked to your account yet.');
        }

        $assessment = $this->activeAssessmentForProfile($profile);

        if (! $assessment instanceof Assessment) {
            return $this->unavailable('No active assessment is available for finance viewing.', $profile);
        }

        return $this->financeForAssessment($assessment, $actor, self::CopyStudent);
    }

    /**
     * @return array<string, mixed>
     */
    public function financeForAssessment(Assessment $assessment, User $actor, string $copyContext = self::CopyStudent): array
    {
        $this->loadAssessment($assessment);
        $enrollment = $assessment->enrollment;

        abort_unless($enrollment instanceof Enrollment, 404);
        abort_unless($this->actorCanAccessAssessment($actor, $assessment), 403);

        $ledgerEntries = $this->ledgerEntries($assessment);
        $payments = $this->payments($assessment);
        $paymentAttempts = $this->paymentAttempts($assessment);
        $balance = $this->ledgerBalance($ledgerEntries);
        $postedPaymentTotal = $ledgerEntries
            ->where('direction', LedgerEntry::DirectionPayment)
            ->reduce(fn (string $carry, LedgerEntry $entry): string => $this->money->add($carry, (string) $entry->amount), '0.00');
        $due = $this->currentDue($assessment, $balance);
        $availableAcknowledgements = $payments
            ->filter(fn (Payment $payment): bool => $this->hasPostedPaymentLedgerEntry($payment))
            ->values();
        $accommodation = $this->activeAccommodation($assessment);
        $studentName = $this->studentName($enrollment->studentProfile);

        return [
            'available' => true,
            'reason' => null,
            'assessment' => $assessment,
            'enrollment' => $enrollment,
            'student_profile' => $enrollment->studentProfile,
            'student' => $enrollment->studentProfile->user,
            'term' => $enrollment->term,
            'copy_context' => $copyContext,
            'generated_at' => CarbonImmutable::now(config('app.timezone')),
            'disclaimer' => self::EVIDENCE_DISCLAIMER,
            'ledger_entries' => $ledgerEntries,
            'payments' => $payments,
            'payment_attempts' => $paymentAttempts,
            'available_acknowledgements' => $availableAcknowledgements,
            'payment_schedule_rows' => $assessment->paymentScheduleRows,
            'active_accommodation' => $accommodation,
            'current_due_source' => $due['source'],
            'current_due_amount' => $due['amount'],
            'summary' => [
                'assessment_id' => (int) $assessment->id,
                'enrollment_id' => (int) $enrollment->id,
                'student_number' => $enrollment->studentProfile->student_number,
                'student_name' => $studentName,
                'program' => $enrollment->studentProfile->program?->code ?: $enrollment->studentProfile->program?->name,
                'term' => $this->termLabel($enrollment->term),
                'total' => $this->money->normalize((string) $assessment->total),
                'required_downpayment' => $this->money->normalize((string) $assessment->required_downpayment),
                'posted_payments' => $postedPaymentTotal,
                'balance' => $balance,
                'current_due' => $due['amount'],
                'payment_status' => $this->paymentStatus($assessment, $balance, $postedPaymentTotal, $paymentAttempts),
                'or_mapping_state' => $this->orMappingState($payments),
            ],
            'state' => [
                'availability_status' => 'Available',
                'notice' => 'Finance details are derived from the active assessment, posted ledger entries, payment evidence, and payment schedule.',
                'student_number' => $enrollment->studentProfile->student_number,
                'student_name' => $studentName,
                'program' => $enrollment->studentProfile->program?->code ?: $enrollment->studentProfile->program?->name ?: 'Not recorded',
                'term' => $this->termLabel($enrollment->term),
                'assessment_total' => $this->formatPeso((string) $assessment->total),
                'required_downpayment' => $this->formatPeso((string) $assessment->required_downpayment),
                'posted_payments' => $this->formatPeso($postedPaymentTotal),
                'ledger_balance' => $this->formatPeso($balance),
                'current_due' => $this->formatPeso($due['amount']),
                'current_due_source' => $due['label'],
                'payment_status' => $this->paymentStatus($assessment, $balance, $postedPaymentTotal, $paymentAttempts),
                'or_mapping_state' => $this->orMappingState($payments),
                'charge_lines' => $this->chargeLines($assessment),
                'ledger_rows' => $this->ledgerRows($ledgerEntries),
                'schedule_rows' => $this->scheduleRows($assessment->paymentScheduleRows),
                'attempt_rows' => $this->attemptRows($paymentAttempts),
                'acknowledgement_rows' => $this->acknowledgementRows($availableAcknowledgements),
                'accommodation_summary' => $this->accommodationSummary($accommodation),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statement(Assessment $assessment, User $actor, string $copyContext = self::CopyStudent): array
    {
        return $this->financeForAssessment($assessment, $actor, $copyContext);
    }

    /**
     * @return array<string, mixed>
     */
    public function billingSlip(Assessment $assessment, User $actor, string $copyContext = self::CopyStudent): array
    {
        $finance = $this->financeForAssessment($assessment, $actor, $copyContext);

        abort_unless($this->money->greaterThanZero($finance['current_due_amount']), 403, 'Billing slip is available only when a positive amount is currently due.');

        return $finance;
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentAcknowledgement(Payment $payment, User $actor, string $copyContext = self::CopyStudent): array
    {
        $payment->loadMissing([
            'studentProfile.user',
            'studentProfile.program',
            'term',
            'paymentAttempt.assessment.enrollment.term',
            'ledgerEntry.enrollment.term',
        ]);

        abort_unless($this->hasPostedPaymentLedgerEntry($payment), 403);
        abort_unless($this->actorCanAccessPayment($actor, $payment), 403);

        $profile = $payment->studentProfile;
        $term = $payment->term;
        $ledgerEntry = $payment->ledgerEntry;

        abort_unless($profile instanceof StudentProfile, 403);
        abort_unless($ledgerEntry instanceof LedgerEntry, 403);

        return [
            'available' => true,
            'payment' => $payment,
            'ledger_entry' => $ledgerEntry,
            'student_profile' => $profile,
            'student' => $profile->user,
            'term' => $term,
            'copy_context' => $copyContext,
            'generated_at' => CarbonImmutable::now(config('app.timezone')),
            'disclaimer' => self::EVIDENCE_DISCLAIMER,
            'summary' => [
                'student_number' => $profile->student_number,
                'student_name' => $this->studentName($profile),
                'program' => $profile->program?->code ?: $profile->program?->name,
                'term' => $term instanceof Term ? $this->termLabel($term) : 'Not recorded',
                'amount' => $this->money->normalize((string) $payment->amount),
                'method' => $payment->method,
                'channel' => $payment->channel,
                'provider_reference' => $payment->provider_reference,
                'ledger_entry_id' => (int) $ledgerEntry->id,
                'paid_at' => $payment->paid_at,
                'verified_at' => $payment->verified_at,
                'or_mapping_state' => filled($payment->or_number) ? (string) $payment->or_number : 'Pending OR Mapping',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public function recordAccess(array $output, User $actor, string $outputType, string $action, ?Request $request = null): void
    {
        if (($output['available'] ?? false) !== true) {
            return;
        }

        [$sourceType, $sourceId, $studentProfileId] = $this->sourceForOutput($output, $outputType);

        DB::table('output_access_logs')->insert([
            'output_type' => $outputType,
            'source_record_type' => $sourceType,
            'source_record_id' => $sourceId,
            'student_profile_id' => $studentProfileId,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->getRoleNames()->first(),
            'action' => $action,
            'copy_context' => $output['copy_context'] ?? self::CopyStudent,
            'request_context' => json_encode([
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'route' => $request?->route()?->getName(),
            ], JSON_THROW_ON_ERROR),
            'status' => 'logged',
            'occurred_at' => Carbon::now(),
        ]);
    }

    public function activeAssessmentForProfile(StudentProfile $profile): ?Assessment
    {
        return Assessment::query()
            ->whereHas('enrollment', fn ($query) => $query->where('student_profile_id', $profile->id))
            ->where('state', Assessment::StateActive)
            ->with(['enrollment.studentProfile.user', 'enrollment.studentProfile.program', 'enrollment.term', 'lines', 'paymentScheduleRows'])
            ->latest('version')
            ->latest('id')
            ->first();
    }

    public function currentDueAmount(Assessment $assessment): string
    {
        $this->loadAssessment($assessment);

        return $this->currentDue($assessment, $this->ledgerBalance($this->ledgerEntries($assessment)))['amount'];
    }

    public function formatPeso(string|int|float $amount): string
    {
        return 'PHP '.number_format((float) $this->money->normalize($amount), 2);
    }

    private function loadAssessment(Assessment $assessment): void
    {
        $assessment->loadMissing([
            'enrollment.studentProfile.user',
            'enrollment.studentProfile.program',
            'enrollment.term',
            'lines',
            'paymentScheduleRows',
        ]);
    }

    /** @return Collection<int, LedgerEntry> */
    private function ledgerEntries(Assessment $assessment): Collection
    {
        return LedgerEntry::query()
            ->with(['payment'])
            ->where('enrollment_id', $assessment->enrollment_id)
            ->where('state', 'posted')
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Payment> */
    private function payments(Assessment $assessment): Collection
    {
        return Payment::query()
            ->with(['ledgerEntry', 'paymentAttempt'])
            ->where('student_profile_id', $assessment->enrollment->student_profile_id)
            ->where('term_id', $assessment->enrollment->term_id)
            ->latest('paid_at')
            ->latest('id')
            ->get();
    }

    /** @return Collection<int, PaymentAttempt> */
    private function paymentAttempts(Assessment $assessment): Collection
    {
        return PaymentAttempt::query()
            ->where('assessment_id', $assessment->id)
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    /** @param Collection<int, LedgerEntry> $entries */
    private function ledgerBalance(Collection $entries): string
    {
        return $entries->reduce(
            fn (string $carry, LedgerEntry $entry): string => $this->money->add($carry, $this->balanceAmount($entry)),
            '0.00',
        );
    }

    private function balanceAmount(LedgerEntry $entry): string
    {
        $amount = $this->money->normalize((string) $entry->amount);

        return match ($entry->direction) {
            LedgerEntry::DirectionPayment,
            LedgerEntry::DirectionDiscount,
            LedgerEntry::DirectionScholarship,
            LedgerEntry::DirectionWaiver => '-'.$amount,
            default => $amount,
        };
    }

    /**
     * @return array{amount:string,label:string,source:Assessment|PaymentScheduleRow}
     */
    private function currentDue(Assessment $assessment, string $balance): array
    {
        $dueRow = $assessment->paymentScheduleRows
            ->where('state', PaymentScheduleRow::StateDue)
            ->sortBy('due_date')
            ->first();

        if ($dueRow instanceof PaymentScheduleRow && $this->money->greaterThanZero((string) $dueRow->amount)) {
            return [
                'amount' => $this->money->min((string) $dueRow->amount, $balance),
                'label' => str((string) $dueRow->category)->headline()->toString(),
                'source' => $dueRow,
            ];
        }

        if ($this->money->greaterThanZero((string) $assessment->required_downpayment)) {
            return [
                'amount' => $this->money->min((string) $assessment->required_downpayment, $balance),
                'label' => 'Required Downpayment',
                'source' => $assessment,
            ];
        }

        return [
            'amount' => $this->money->greaterThanZero($balance) ? $balance : '0.00',
            'label' => 'Current Balance',
            'source' => $assessment,
        ];
    }

    private function activeAccommodation(Assessment $assessment): ?FinancialAccommodation
    {
        return FinancialAccommodation::query()
            ->where('student_profile_id', $assessment->enrollment->student_profile_id)
            ->where('term_id', $assessment->enrollment->term_id)
            ->where('status', FinancialAccommodation::StatusActive)
            ->orderByDesc('effective_from')
            ->first();
    }

    private function paymentStatus(Assessment $assessment, string $balance, string $postedPayments, Collection $attempts): string
    {
        if (! $this->money->greaterThanZero($balance)) {
            return 'Full Paid';
        }

        if ($attempts->contains(fn (PaymentAttempt $attempt): bool => in_array($attempt->status, ['pending', 'under_review'], true))) {
            return 'Payment Pending or Under Review';
        }

        if (! $this->money->greaterThanZero($postedPayments)) {
            return 'Unpaid';
        }

        if ($assessment->paymentScheduleRows->where('state', PaymentScheduleRow::StateDue)->isNotEmpty()) {
            return 'Installment';
        }

        return 'Partially Paid';
    }

    /** @param Collection<int, Payment> $payments */
    private function orMappingState(Collection $payments): string
    {
        $posted = $payments->first(fn (Payment $payment): bool => $this->hasPostedPaymentLedgerEntry($payment));

        if (! $posted instanceof Payment) {
            return 'No posted payment yet';
        }

        return filled($posted->or_number) ? 'Mapped OR '.$posted->or_number : 'Pending OR Mapping';
    }

    /** @return list<array<string, mixed>> */
    private function chargeLines(Assessment $assessment): array
    {
        return $assessment->lines
            ->map(fn ($line): array => [
                'description' => $line->description_snapshot,
                'quantity' => $line->quantity,
                'rate' => $this->formatPeso((string) $line->rate),
                'amount' => $this->formatPeso((string) $line->amount),
            ])
            ->values()
            ->all();
    }

    /** @param Collection<int, LedgerEntry> $entries
     * @return list<array<string, mixed>>
     */
    private function ledgerRows(Collection $entries): array
    {
        $rows = [];

        foreach ($entries as $entry) {
            $rows[] = [
                'posted_at' => $this->dateValue($entry->posted_at),
                'direction' => str($entry->direction)->headline()->toString(),
                'category' => str($entry->category)->headline()->toString(),
                'description' => $entry->description,
                'amount' => $this->formatPeso((string) $entry->amount),
            ];
        }

        return $rows;
    }

    /** @param Collection<int, PaymentScheduleRow> $rows
     * @return list<array<string, mixed>>
     */
    private function scheduleRows(Collection $rows): array
    {
        $scheduleRows = [];

        foreach ($rows->sortBy('sequence') as $row) {
            $scheduleRows[] = [
                'category' => str($row->category)->headline()->toString(),
                'due_date' => $this->dateValue($row->due_date),
                'amount' => $this->formatPeso((string) $row->amount),
                'state' => str($row->state)->headline()->toString(),
            ];
        }

        return $scheduleRows;
    }

    /** @param Collection<int, PaymentAttempt> $attempts
     * @return list<array<string, mixed>>
     */
    private function attemptRows(Collection $attempts): array
    {
        return $attempts
            ->map(fn (PaymentAttempt $attempt): array => [
                'reference' => $attempt->internal_reference,
                'provider' => $attempt->provider,
                'status' => str($attempt->status)->headline()->toString(),
                'amount' => $this->formatPeso((string) $attempt->amount),
            ])
            ->values()
            ->all();
    }

    /** @param Collection<int, Payment> $payments
     * @return list<array<string, mixed>>
     */
    private function acknowledgementRows(Collection $payments): array
    {
        $rows = [];

        foreach ($payments as $payment) {
            $rows[] = [
                'payment_id' => (int) $payment->id,
                'paid_at' => $this->dateValue($payment->paid_at),
                'reference' => $payment->provider_reference ?? 'Manual payment',
                'amount' => $this->formatPeso((string) $payment->amount),
                'or_mapping' => filled($payment->or_number) ? (string) $payment->or_number : 'Pending OR Mapping',
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function accommodationSummary(?FinancialAccommodation $accommodation): array
    {
        if (! $accommodation instanceof FinancialAccommodation) {
            return [
                'status' => 'No active Financial Accommodation',
                'basis' => '-',
                'covered_amount' => 'PHP 0.00',
                'next_due' => '-',
            ];
        }

        $nextDue = $accommodation->paymentScheduleRows()
            ->where('state', PaymentScheduleRow::StateDue)
            ->orderBy('due_date')
            ->first();

        return [
            'status' => str($accommodation->status)->headline()->toString(),
            'basis' => str($accommodation->basis)->replace('_', ' ')->headline()->toString(),
            'covered_amount' => $this->formatPeso((string) $accommodation->covered_amount),
            'next_due' => $nextDue instanceof PaymentScheduleRow ? $this->dateValue($nextDue->due_date) : '-',
        ];
    }

    private function actorCanAccessAssessment(User $actor, Assessment $assessment): bool
    {
        return $actor->can('process-payments')
            || (int) $assessment->enrollment->studentProfile->user_id === (int) $actor->id;
    }

    private function actorCanAccessPayment(User $actor, Payment $payment): bool
    {
        $profile = $payment->studentProfile;

        return $actor->can('process-payments')
            || ($profile instanceof StudentProfile && (int) $profile->user_id === (int) $actor->id);
    }

    private function hasPostedPaymentLedgerEntry(Payment $payment): bool
    {
        return $payment->evidence_status === 'verified'
            && $payment->ledgerEntry instanceof LedgerEntry
            && $payment->ledgerEntry->state === 'posted';
    }

    private function studentName(StudentProfile $profile): string
    {
        if ($profile->user instanceof User && filled($profile->user->name)) {
            return $profile->user->name;
        }

        return collect([$profile->first_name, $profile->middle_name, $profile->last_name])
            ->filter()
            ->implode(' ');
    }

    private function termLabel(?Term $term): string
    {
        return $term instanceof Term ? $term->label : 'Not recorded';
    }

    private function dateValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not recorded';
        }

        return Carbon::parse($value)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{0:class-string|string,1:int,2:int|null}
     */
    private function sourceForOutput(array $output, string $outputType): array
    {
        if ($outputType === self::OutputPaymentAcknowledgement && ($output['payment'] ?? null) instanceof Payment) {
            /** @var Payment $payment */
            $payment = $output['payment'];

            return [Payment::class, (int) $payment->id, (int) $payment->student_profile_id];
        }

        if ($outputType === self::OutputBillingSlip && ($output['current_due_source'] ?? null) instanceof PaymentScheduleRow) {
            /** @var PaymentScheduleRow $row */
            $row = $output['current_due_source'];

            return [PaymentScheduleRow::class, (int) $row->id, (int) ($output['student_profile']->id ?? 0)];
        }

        /** @var Assessment $assessment */
        $assessment = $output['assessment'];

        return [Assessment::class, (int) $assessment->id, (int) $assessment->enrollment->student_profile_id];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $reason, ?StudentProfile $profile = null): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'student_profile' => $profile,
            'state' => [
                'availability_status' => 'Unavailable',
                'notice' => $reason,
                'student_number' => $profile instanceof StudentProfile ? $profile->student_number : 'Not available',
                'student_name' => $profile instanceof StudentProfile ? $this->studentName($profile) : 'Not available',
                'program' => $profile instanceof StudentProfile && $profile->program !== null ? $profile->program->code : 'Not available',
                'term' => 'Not available',
                'assessment_total' => 'PHP 0.00',
                'required_downpayment' => 'PHP 0.00',
                'posted_payments' => 'PHP 0.00',
                'ledger_balance' => 'PHP 0.00',
                'current_due' => 'PHP 0.00',
                'current_due_source' => 'Unavailable',
                'payment_status' => 'Unavailable',
                'or_mapping_state' => 'Unavailable',
                'charge_lines' => [],
                'ledger_rows' => [],
                'schedule_rows' => [],
                'attempt_rows' => [],
                'acknowledgement_rows' => [],
                'accommodation_summary' => [
                    'status' => 'No active Financial Accommodation',
                    'basis' => '-',
                    'covered_amount' => 'PHP 0.00',
                    'next_due' => '-',
                ],
            ],
        ];
    }
}
