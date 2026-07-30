<?php

namespace App\Actions\Finance;

use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentScheduleRow;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

class PaymentAllocationService
{
    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @return list<array{target_type:string,target_id:int,description:string,amount:string}>
     */
    public function preview(Enrollment $enrollment, string $amount): array
    {
        $normalizedAmount = $this->money->normalize($amount);

        if (! $this->money->greaterThanZero($normalizedAmount)) {
            return [];
        }

        if ($this->money->toCents($normalizedAmount) > $this->money->toCents($this->eligibleBalance($enrollment))) {
            throw new RuntimeException('Payment amount cannot exceed the eligible outstanding balance.');
        }

        return $this->automaticTargets($enrollment, $normalizedAmount);
    }

    /**
     * @return list<array{target_type:string,target_id:int,description:string,amount:string}>
     */
    public function eligibleTargets(Enrollment $enrollment): array
    {
        $eligibleBalance = $this->eligibleBalance($enrollment);

        return $this->money->greaterThanZero($eligibleBalance)
            ? $this->automaticTargets($enrollment, $eligibleBalance)
            : [];
    }

    /**
     * @param  list<array{target_type:string,target_id:int,description?:string,amount:string}>|null  $requested
     * @return Collection<int, LedgerEntry>
     */
    public function post(
        Payment $payment,
        Enrollment $enrollment,
        string $amount,
        ?array $requested,
        ?User $actor,
        CarbonImmutable $timestamp,
        string $description,
    ): Collection {
        $normalizedAmount = $this->money->normalize($amount);
        $eligibleBalance = $this->eligibleBalance($enrollment);

        if ($this->money->toCents($normalizedAmount) > $this->money->toCents($eligibleBalance)) {
            throw new RuntimeException('Payment amount cannot exceed the eligible outstanding balance.');
        }

        $existingAllocations = $payment->allocations()
            ->with('ledgerEntry')
            ->lockForUpdate()
            ->get();

        if ($existingAllocations->isNotEmpty()) {
            $existingTotal = $this->money->normalize((string) $existingAllocations->sum('amount'));
            $existingLedgerEntries = $existingAllocations
                ->pluck('ledgerEntry')
                ->filter(fn (?LedgerEntry $entry): bool => $entry instanceof LedgerEntry)
                ->values();

            if ($existingTotal !== $normalizedAmount
                || $existingLedgerEntries->count() !== $existingAllocations->count()) {
                throw new RuntimeException('Existing payment allocation state does not match the verified payment.');
            }

            return $existingLedgerEntries;
        }

        $targets = $requested ?? $this->automaticTargets($enrollment, $normalizedAmount);
        $total = collect($targets)->reduce(
            fn (string $carry, array $target): string => $this->money->add($carry, $target['amount']),
            '0.00',
        );

        if ($total !== $normalizedAmount) {
            throw new RuntimeException('The sum of allocations must equal the total payment amount.');
        }

        return collect($targets)->map(function (array $target) use ($payment, $enrollment, $actor, $timestamp, $description): LedgerEntry {
            $normalizedTargetAmount = $this->money->normalize($target['amount']);
            $validatedTarget = $this->validatedTarget($enrollment, $target, $normalizedTargetAmount);
            $allocation = PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                ...$validatedTarget['columns'],
                'amount' => $normalizedTargetAmount,
            ]);

            return LedgerEntry::query()->create([
                'student_profile_id' => $enrollment->student_profile_id,
                'term_id' => $validatedTarget['term_id'],
                'enrollment_id' => $validatedTarget['enrollment_id'],
                'direction' => LedgerEntry::DirectionPayment,
                'category' => 'payment',
                'amount' => $allocation->amount,
                'source_type' => PaymentAllocation::class,
                'source_id' => $allocation->id,
                'payment_id' => $payment->id,
                'payment_allocation_id' => $allocation->id,
                'description' => $target['description'] ?? $description,
                'posted_by' => $actor?->id,
                'posted_at' => $timestamp,
                'state' => 'posted',
            ]);
        });
    }

    private function eligibleBalance(Enrollment $enrollment): string
    {
        $balance = '0.00';

        foreach (LedgerEntry::query()
            ->where('student_profile_id', $enrollment->student_profile_id)
            ->where('state', 'posted')
            ->lockForUpdate()
            ->get() as $entry) {
            $balance = match ($entry->direction) {
                LedgerEntry::DirectionPayment,
                LedgerEntry::DirectionDiscount,
                LedgerEntry::DirectionScholarship,
                LedgerEntry::DirectionWaiver,
                LedgerEntry::DirectionReversal => $this->money->subtract($balance, (string) $entry->amount),
                default => $this->money->add($balance, (string) $entry->amount),
            };
        }

        return $balance;
    }

    /**
     * @return list<array{target_type:string,target_id:int,description:string,amount:string}>
     */
    private function automaticTargets(Enrollment $enrollment, string $amount): array
    {
        $assessment = Assessment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('state', Assessment::StateActive)
            ->with(['paymentScheduleRows', 'lines'])
            ->lockForUpdate()
            ->firstOrFail();
        $remaining = $amount;
        $targets = [];
        $assessmentLineIds = $assessment->lines->pluck('id');
        $paymentScheduleRowIds = $assessment->paymentScheduleRows->pluck('id');

        foreach ($this->outstandingScheduleRows($assessment) as $outstandingScheduleRow) {
            $row = $outstandingScheduleRow['row'];
            $remaining = $this->appendTarget(
                $targets,
                PaymentScheduleRow::class,
                $row->id,
                str((string) $row->category)->replace('_', ' ')->headline()->toString(),
                $outstandingScheduleRow['amount'],
                $remaining,
            );
        }

        $paymentScheduleCoverage = $this->money->normalize((string) PaymentAllocation::query()
            ->whereIn('payment_schedule_row_id', $paymentScheduleRowIds)
            ->sum('amount'));
        $paymentScheduleCoverage = collect($targets)
            ->where('target_type', PaymentScheduleRow::class)
            ->reduce(
                fn (string $carry, array $target): string => $this->money->add($carry, $target['amount']),
                $paymentScheduleCoverage,
            );

        foreach ($assessment->lines->sortBy('id') as $line) {
            $targetOutstanding = $this->remainingFor(
                'assessment_line_id',
                $line->id,
                (string) $line->amount,
            );
            $crossTargetCoverage = $this->money->min($targetOutstanding, $paymentScheduleCoverage);
            $targetOutstanding = $this->money->subtract($targetOutstanding, $crossTargetCoverage);
            $paymentScheduleCoverage = $this->money->subtract($paymentScheduleCoverage, $crossTargetCoverage);
            $remaining = $this->appendTarget(
                $targets,
                AssessmentLine::class,
                $line->id,
                (string) $line->description_snapshot,
                $targetOutstanding,
                $remaining,
            );
        }

        if ($this->money->greaterThanZero($remaining)) {
            $priorBalanceEntries = LedgerEntry::query()
                ->where('student_profile_id', $enrollment->student_profile_id)
                ->where('state', 'posted')
                ->where(function ($query): void {
                    $query->whereIn('direction', [
                        LedgerEntry::DirectionCharge,
                        LedgerEntry::DirectionPenalty,
                        LedgerEntry::DirectionRefund,
                    ])->orWhere(function ($query): void {
                        $query->where('direction', LedgerEntry::DirectionAdjustment)
                            ->where('amount', '>', 0);
                    });
                })
                ->when(
                    $assessmentLineIds->isNotEmpty(),
                    fn ($query) => $query->where(function ($query) use ($assessmentLineIds): void {
                        $query->where('source_type', '!=', AssessmentLine::class)
                            ->orWhereNotIn('source_id', $assessmentLineIds);
                    }),
                )
                ->orderByRaw('CASE WHEN term_id = ? THEN 0 ELSE 1 END', [$enrollment->term_id])
                ->orderBy('posted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($priorBalanceEntries as $entry) {
                $remaining = $this->appendTarget(
                    $targets,
                    LedgerEntry::class,
                    $entry->id,
                    (string) $entry->description,
                    $this->remainingFor('prior_balance_ledger_entry_id', $entry->id, (string) $entry->amount),
                    $remaining,
                );
            }
        }

        if ($this->money->greaterThanZero($remaining)) {
            throw new RuntimeException('Payment amount cannot exceed the eligible allocation targets.');
        }

        return $targets;
    }

    /**
     * @return Collection<int, array{row: PaymentScheduleRow, amount: string}>
     */
    public function outstandingScheduleRows(Assessment $assessment): Collection
    {
        $assessment->loadMissing(['paymentScheduleRows', 'lines']);
        $paymentScheduleRowIds = $assessment->paymentScheduleRows->pluck('id');
        $assessmentLineCoverage = $this->money->normalize((string) PaymentAllocation::query()
            ->whereIn('assessment_line_id', $assessment->lines->pluck('id'))
            ->sum('amount'));
        $allocatedByScheduleRow = PaymentAllocation::query()
            ->whereIn('payment_schedule_row_id', $paymentScheduleRowIds)
            ->selectRaw('payment_schedule_row_id, SUM(amount) as allocated_amount')
            ->groupBy('payment_schedule_row_id')
            ->pluck('allocated_amount', 'payment_schedule_row_id');
        $outstandingRows = collect();

        foreach ($assessment->paymentScheduleRows->where('state', PaymentScheduleRow::StateDue)->sortBy(['due_date', 'sequence']) as $row) {
            $targetOutstanding = $this->money->subtract(
                (string) $row->amount,
                (string) ($allocatedByScheduleRow->get($row->id) ?? '0.00'),
            );
            $crossTargetCoverage = $this->money->min($targetOutstanding, $assessmentLineCoverage);
            $targetOutstanding = $this->money->subtract($targetOutstanding, $crossTargetCoverage);
            $assessmentLineCoverage = $this->money->subtract($assessmentLineCoverage, $crossTargetCoverage);

            if ($this->money->greaterThanZero($targetOutstanding)) {
                $outstandingRows->push([
                    'row' => $row,
                    'amount' => $targetOutstanding,
                ]);
            }
        }

        return $outstandingRows;
    }

    /**
     * @param  list<array{target_type:string,target_id:int,description:string,amount:string}>  $targets
     */
    private function appendTarget(
        array &$targets,
        string $type,
        int $id,
        string $description,
        string $eligible,
        string $remaining,
    ): string {
        if (! $this->money->greaterThanZero($remaining) || ! $this->money->greaterThanZero($eligible)) {
            return $remaining;
        }

        $allocated = $this->money->min($eligible, $remaining);
        $targets[] = [
            'target_type' => $type,
            'target_id' => $id,
            'description' => $description,
            'amount' => $allocated,
        ];

        return $this->money->subtract($remaining, $allocated);
    }

    private function remainingFor(string $column, int $id, string $amount): string
    {
        $allocated = PaymentAllocation::query()->where($column, $id)->sum('amount');

        return $this->money->subtract($amount, (string) $allocated);
    }

    /**
     * @param  array{target_type:string,target_id:int,description?:string,amount:string}  $target
     * @return array{
     *     columns:array{assessment_line_id:?int,payment_schedule_row_id:?int,prior_balance_ledger_entry_id:?int},
     *     enrollment_id:?int,
     *     term_id:?int
     * }
     */
    private function validatedTarget(Enrollment $enrollment, array $target, string $amount): array
    {
        $type = $target['target_type'];
        $id = $target['target_id'];
        $targetOutstanding = '0.00';
        $record = null;

        $valid = match ($type) {
            AssessmentLine::class => ($record = AssessmentLine::query()
                ->whereKey($id)
                ->whereHas('assessment', fn ($query) => $query
                    ->where('enrollment_id', $enrollment->id)
                    ->where('state', Assessment::StateActive))
                ->lockForUpdate()
                ->first()) instanceof AssessmentLine
                    && ($targetOutstanding = $this->remainingFor(
                        'assessment_line_id',
                        $record->id,
                        (string) $record->amount,
                    )) !== '',
            PaymentScheduleRow::class => ($record = PaymentScheduleRow::query()
                ->whereKey($id)
                ->where('state', PaymentScheduleRow::StateDue)
                ->whereHas('assessment', fn ($query) => $query
                    ->where('enrollment_id', $enrollment->id)
                    ->where('state', Assessment::StateActive))
                ->lockForUpdate()
                ->first()) instanceof PaymentScheduleRow
                    && ($targetOutstanding = $this->remainingFor(
                        'payment_schedule_row_id',
                        $record->id,
                        (string) $record->amount,
                    )) !== '',
            LedgerEntry::class => ($record = LedgerEntry::query()
                ->whereKey($id)
                ->where('student_profile_id', $enrollment->student_profile_id)
                ->where('state', 'posted')
                ->where(function ($query): void {
                    $query->whereIn('direction', [
                        LedgerEntry::DirectionCharge,
                        LedgerEntry::DirectionPenalty,
                        LedgerEntry::DirectionRefund,
                    ])->orWhere(function ($query): void {
                        $query->where('direction', LedgerEntry::DirectionAdjustment)
                            ->where('amount', '>', 0);
                    });
                })
                ->lockForUpdate()
                ->first()) instanceof LedgerEntry
                    && ($targetOutstanding = $this->remainingFor(
                        'prior_balance_ledger_entry_id',
                        $record->id,
                        (string) $record->amount,
                    )) !== '',
            default => false,
        };

        if (! $valid
            || ! $this->money->greaterThanZero($amount)
            || $this->money->toCents($amount) > $this->money->toCents($targetOutstanding)) {
            throw new RuntimeException('Payment allocation target is not eligible.');
        }

        return [
            'columns' => [
                'assessment_line_id' => $type === AssessmentLine::class ? $id : null,
                'payment_schedule_row_id' => $type === PaymentScheduleRow::class ? $id : null,
                'prior_balance_ledger_entry_id' => $type === LedgerEntry::class ? $id : null,
            ],
            'enrollment_id' => $record instanceof LedgerEntry
                ? $record->enrollment_id
                : $enrollment->id,
            'term_id' => $record instanceof LedgerEntry
                ? $record->term_id
                : $enrollment->term_id,
        ];
    }
}
