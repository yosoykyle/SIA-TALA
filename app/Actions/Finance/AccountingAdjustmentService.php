<?php

namespace App\Actions\Finance;

use App\Models\AccountingAdjustment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class AccountingAdjustmentService
{
    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @param  array{student_profile_id:mixed,term_id?:mixed,enrollment_id?:mixed,source_ledger_entry_id?:mixed,adjustment_type:mixed,amount?:mixed,reason:mixed,evidence_reference?:mixed}  $data
     * @return array{adjustment_id:int,ledger_entry_id:int,current_balance:string,ledger_amount:string}
     *
     * @throws AuthorizationException
     */
    public function post(array $data, User $actor, ?CarbonImmutable $postedAt = null): array
    {
        if (! $this->canPostAccountingAdjustment($actor)) {
            throw new AuthorizationException('Only authorized Accounting users can post accounting adjustments.');
        }

        $adjustmentType = (string) ($data['adjustment_type'] ?? '');

        if (! array_key_exists($adjustmentType, AccountingAdjustment::typeOptions())) {
            throw new RuntimeException('Unsupported accounting adjustment type.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if (Str::length($reason) < 10) {
            throw new RuntimeException('Accounting adjustment reason must be at least 10 characters.');
        }

        if (Str::length($reason) > 2000) {
            throw new RuntimeException('Accounting adjustment reason must not exceed 2000 characters.');
        }

        $evidenceReference = filled($data['evidence_reference'] ?? null)
            ? trim((string) $data['evidence_reference'])
            : null;

        if ($evidenceReference !== null && Str::length($evidenceReference) > 255) {
            throw new RuntimeException('Evidence reference must not exceed 255 characters.');
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $timestamp = $postedAt ?? $now;

        if ($timestamp->greaterThan($now)) {
            throw new RuntimeException('Accounting adjustment date cannot be in the future.');
        }

        return DB::transaction(function () use ($data, $actor, $adjustmentType, $reason, $evidenceReference, $timestamp): array {
            $studentProfile = StudentProfile::query()
                ->lockForUpdate()
                ->findOrFail((int) ($data['student_profile_id'] ?? 0));

            $enrollment = $this->resolveEnrollment($studentProfile, $data);
            $termId = $this->resolveTermId($enrollment, $data);
            $sourceLedgerEntry = $this->resolveSourceLedgerEntry($studentProfile, $enrollment, $termId, $data, $adjustmentType);
            $ledgerAmount = $this->ledgerAmountFor($adjustmentType, $data, $sourceLedgerEntry);
            $currentBalance = $this->ledgerBalanceFor($studentProfile, $termId);
            $ledgerDirection = $adjustmentType === AccountingAdjustment::TypeLedgerEntryReversal
                ? LedgerEntry::DirectionReversal
                : LedgerEntry::DirectionAdjustment;
            $newBalance = $this->money->add($currentBalance, $this->balanceEffect($ledgerDirection, $ledgerAmount));

            $adjustment = AccountingAdjustment::query()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $termId,
                'enrollment_id' => $enrollment?->id,
                'source_ledger_entry_id' => $sourceLedgerEntry?->id,
                'adjustment_type' => $adjustmentType,
                'amount' => $ledgerAmount,
                'reason' => $reason,
                'evidence_reference' => $evidenceReference,
                'posted_at' => $timestamp,
                'posted_by' => $actor->id,
            ]);

            $ledgerEntry = LedgerEntry::query()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $termId,
                'enrollment_id' => $enrollment?->id,
                'direction' => $ledgerDirection,
                'category' => $adjustmentType === AccountingAdjustment::TypeLedgerEntryReversal
                    ? AccountingAdjustment::LedgerCategoryReversal
                    : AccountingAdjustment::LedgerCategoryAdjustment,
                'description' => $this->ledgerDescription($adjustmentType, $sourceLedgerEntry),
                'amount' => $ledgerAmount,
                'source_type' => AccountingAdjustment::class,
                'source_id' => $adjustment->id,
                'reverses_entry_id' => $adjustmentType === AccountingAdjustment::TypeLedgerEntryReversal
                    ? $sourceLedgerEntry?->id
                    : null,
                'adjusts_entry_id' => $adjustmentType !== AccountingAdjustment::TypeLedgerEntryReversal
                    ? $sourceLedgerEntry?->id
                    : null,
                'posted_at' => $timestamp,
                'posted_by' => $actor->id,
                'state' => 'posted',
            ]);

            $adjustment->forceFill([
                'ledger_entry_id' => $ledgerEntry->id,
            ])->save();

            $this->recordAdjustmentAudit($adjustment, $ledgerEntry, $actor, $timestamp, $newBalance);

            return [
                'adjustment_id' => $adjustment->id,
                'ledger_entry_id' => $ledgerEntry->id,
                'current_balance' => $newBalance,
                'ledger_amount' => $ledgerAmount,
            ];
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveEnrollment(StudentProfile $studentProfile, array $data): ?Enrollment
    {
        if (blank($data['enrollment_id'] ?? null)) {
            return null;
        }

        $enrollment = Enrollment::query()
            ->lockForUpdate()
            ->findOrFail((int) $data['enrollment_id']);

        if ((int) $enrollment->student_profile_id !== (int) $studentProfile->id) {
            throw new RuntimeException('Selected enrollment must belong to the selected student.');
        }

        if (filled($data['term_id'] ?? null) && (int) $enrollment->term_id !== (int) $data['term_id']) {
            throw new RuntimeException('Selected enrollment must belong to the selected term.');
        }

        return $enrollment;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveTermId(?Enrollment $enrollment, array $data): ?int
    {
        if ($enrollment instanceof Enrollment) {
            return (int) $enrollment->term_id;
        }

        if (blank($data['term_id'] ?? null)) {
            return null;
        }

        return (int) $data['term_id'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSourceLedgerEntry(
        StudentProfile $studentProfile,
        ?Enrollment $enrollment,
        ?int $termId,
        array $data,
        string $adjustmentType,
    ): ?LedgerEntry {
        if ($adjustmentType === AccountingAdjustment::TypeLedgerEntryReversal && blank($data['source_ledger_entry_id'] ?? null)) {
            throw new RuntimeException('A source ledger entry is required for reversals.');
        }

        if (blank($data['source_ledger_entry_id'] ?? null)) {
            return null;
        }

        $sourceLedgerEntry = LedgerEntry::query()
            ->lockForUpdate()
            ->findOrFail((int) $data['source_ledger_entry_id']);

        if ((int) $sourceLedgerEntry->student_profile_id !== (int) $studentProfile->id) {
            throw new RuntimeException('Source ledger entry must belong to the selected student.');
        }

        if ($termId !== null && (int) $sourceLedgerEntry->term_id !== $termId) {
            throw new RuntimeException('Source ledger entry must belong to the selected term.');
        }

        if ($enrollment instanceof Enrollment && (int) $sourceLedgerEntry->enrollment_id !== (int) $enrollment->id) {
            throw new RuntimeException('Source ledger entry must belong to the selected enrollment.');
        }

        if ($sourceLedgerEntry->state !== 'posted') {
            throw new RuntimeException('Source ledger entry must be posted before it can be adjusted or reversed.');
        }

        if ($adjustmentType === AccountingAdjustment::TypeLedgerEntryReversal) {
            if (! in_array($sourceLedgerEntry->direction, AccountingAdjustment::reversibleDirections(), true)) {
                throw new RuntimeException('Selected ledger direction cannot be reversed by this workflow.');
            }

            if ($this->money->toCents((string) $sourceLedgerEntry->amount) === 0) {
                throw new RuntimeException('Zero-amount ledger entries cannot be reversed.');
            }

            $alreadyReversed = LedgerEntry::query()
                ->where('reverses_entry_id', $sourceLedgerEntry->id)
                ->exists();

            if ($alreadyReversed) {
                throw new RuntimeException('Selected ledger entry has already been reversed.');
            }
        }

        return $sourceLedgerEntry;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ledgerAmountFor(string $adjustmentType, array $data, ?LedgerEntry $sourceLedgerEntry): string
    {
        if ($adjustmentType === AccountingAdjustment::TypeLedgerEntryReversal) {
            if (! $sourceLedgerEntry instanceof LedgerEntry) {
                throw new RuntimeException('A source ledger entry is required for reversals.');
            }

            return $this->balanceEffect((string) $sourceLedgerEntry->direction, (string) $sourceLedgerEntry->amount);
        }

        if (blank($data['amount'] ?? null)) {
            throw new RuntimeException('Accounting adjustment amount is required.');
        }

        $amount = $this->money->normalize((string) $data['amount']);

        if (! $this->money->greaterThanZero($amount)) {
            throw new RuntimeException('Accounting adjustment amount must be greater than zero.');
        }

        if ($adjustmentType === AccountingAdjustment::TypeStudentAccountCredit) {
            return $this->money->subtract('0.00', $amount);
        }

        return $amount;
    }

    private function ledgerDescription(string $adjustmentType, ?LedgerEntry $sourceLedgerEntry): string
    {
        if ($adjustmentType === AccountingAdjustment::TypeLedgerEntryReversal && $sourceLedgerEntry instanceof LedgerEntry) {
            return "Reversal of ledger entry #{$sourceLedgerEntry->id}";
        }

        return AccountingAdjustment::typeOptions()[$adjustmentType];
    }

    private function canPostAccountingAdjustment(User $actor): bool
    {
        if ($actor->hasRole(User::StaffRoleAccounting)) {
            return true;
        }

        try {
            return $actor->can('post-accounting-adjustments');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    private function ledgerBalanceFor(StudentProfile $studentProfile, ?int $termId): string
    {
        return LedgerEntry::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('state', 'posted')
            ->when($termId !== null, fn ($query) => $query->where('term_id', $termId))
            ->oldest('posted_at')
            ->oldest('id')
            ->get(['direction', 'amount'])
            ->reduce(
                fn (string $balance, LedgerEntry $entry): string => $this->money->add(
                    $balance,
                    $this->balanceEffect((string) $entry->direction, (string) $entry->amount),
                ),
                '0.00',
            );
    }

    private function balanceEffect(string $direction, string $amount): string
    {
        return match ($direction) {
            LedgerEntry::DirectionPayment,
            LedgerEntry::DirectionDiscount,
            LedgerEntry::DirectionScholarship,
            LedgerEntry::DirectionWaiver,
            LedgerEntry::DirectionReversal => $this->money->subtract('0.00', $amount),
            default => $this->money->normalize($amount),
        };
    }

    private function recordAdjustmentAudit(
        AccountingAdjustment $adjustment,
        LedgerEntry $ledgerEntry,
        User $actor,
        CarbonImmutable $recordedAt,
        string $balanceAfter,
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'accounting_adjustment',
            'description' => 'Accounting adjustment posted.',
            'subject_type' => AccountingAdjustment::class,
            'subject_id' => $adjustment->id,
            'event' => 'accounting_adjustment_posted',
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'adjustment_type' => $adjustment->adjustment_type,
                'source_ledger_entry_id' => $adjustment->source_ledger_entry_id,
                'ledger_entry_id' => $ledgerEntry->id,
                'ledger_direction' => $ledgerEntry->direction,
                'ledger_category' => $ledgerEntry->category,
                'ledger_source_type' => $ledgerEntry->source_type,
                'ledger_source_id' => $ledgerEntry->source_id,
                'reverses_entry_id' => $ledgerEntry->reverses_entry_id,
                'adjusts_entry_id' => $ledgerEntry->adjusts_entry_id,
                'amount' => $adjustment->amount,
                'balance_after' => $balanceAfter,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $recordedAt->toDateTimeString(),
            'updated_at' => $recordedAt->toDateTimeString(),
        ]);
    }
}
