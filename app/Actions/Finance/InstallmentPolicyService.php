<?php

namespace App\Actions\Finance;

use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class InstallmentPolicyService
{
    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @return array{
     *     enrollment_id:int,
     *     policy_id:int|null,
     *     principal_amount:string,
     *     total_paid:string,
     *     total_outstanding:string,
     *     has_active_promissory:bool,
     *     is_finance_cleared:bool,
     *     milestones:array<int, array<string, mixed>>
     * }
     */
    public function evaluateEnrollment(int $enrollmentId, ?CarbonImmutable $asOf = null): array
    {
        $evaluatedAt = $asOf ?? CarbonImmutable::now();
        $enrollment = $this->resolveEnrollment($enrollmentId);
        $policy = $this->resolvePolicyForEnrollment($enrollment);
        $principalAmount = $this->principalAmountForEnrollment($enrollment);
        $totalPaid = $this->totalConfirmedPaymentsForEnrollment($enrollment);
        $hasActivePromissory = $this->hasActivePromissory($enrollment, $evaluatedAt);

        if ($policy === null) {
            $isCleared = $this->money->isZeroOrNegative(
                $this->money->subtract($principalAmount, $totalPaid),
            );

            return [
                'enrollment_id' => (int) $enrollment->id,
                'policy_id' => null,
                'principal_amount' => $principalAmount,
                'total_paid' => $totalPaid,
                'total_outstanding' => $this->money->subtract($principalAmount, $totalPaid),
                'has_active_promissory' => $hasActivePromissory,
                'is_finance_cleared' => $isCleared,
                'milestones' => [],
            ];
        }

        $milestones = $this->milestonesForPolicy((int) $policy->id);
        $baseDate = $this->enrollmentBaseDate($enrollment);
        $remainingPaidCents = $this->money->toCents($totalPaid);
        $evaluatedMilestones = [];
        $totalOutstanding = '0.00';

        foreach ($milestones as $milestone) {
            $requiredAmount = $this->money->multiplyPercent($principalAmount, (string) $milestone->required_percentage);
            $requiredCents = $this->money->toCents($requiredAmount);
            $appliedPaidCents = min($remainingPaidCents, $requiredCents);
            $remainingPaidCents -= $appliedPaidCents;
            $appliedPaid = $this->money->fromCents($appliedPaidCents);
            $outstanding = $this->money->subtract($requiredAmount, $appliedPaid);
            $dueAt = $this->dueAt($baseDate, (int) $milestone->month_offset, (string) $policy->due_day_rule);
            $graceEndsAt = $dueAt->addDays((int) $policy->grace_days);
            $status = $this->milestoneStatus($outstanding, $evaluatedAt, $graceEndsAt);
            $penaltyBase = (bool) $policy->allow_partial_payments
                ? $outstanding
                : ($this->money->greaterThanZero($outstanding) ? $requiredAmount : '0.00');
            $penaltyPerCycle = $this->money->multiplyPercent($penaltyBase, (string) $policy->penalty_rate);
            $cycleKeys = $this->overdueCycleKeys(
                $status,
                $graceEndsAt,
                $evaluatedAt,
                (string) $policy->penalty_frequency,
            );

            $evaluatedMilestones[] = [
                'milestone_id' => (int) $milestone->id,
                'sequence' => (int) $milestone->sequence,
                'required_amount' => $requiredAmount,
                'paid_amount' => $appliedPaid,
                'outstanding_amount' => $outstanding,
                'due_at' => $dueAt->toIso8601String(),
                'grace_ends_at' => $graceEndsAt->toIso8601String(),
                'status' => $status,
                'overdue_cycle_keys' => $cycleKeys,
                'penalty_base_amount' => $penaltyBase,
                'penalty_amount_per_cycle' => $penaltyPerCycle,
            ];

            $totalOutstanding = $this->money->add($totalOutstanding, $outstanding);
        }

        $isFinanceCleared = $this->money->isZeroOrNegative($totalOutstanding);

        return [
            'enrollment_id' => (int) $enrollment->id,
            'policy_id' => (int) $policy->id,
            'principal_amount' => $principalAmount,
            'total_paid' => $totalPaid,
            'total_outstanding' => $totalOutstanding,
            'has_active_promissory' => $hasActivePromissory,
            'is_finance_cleared' => $isFinanceCleared,
            'milestones' => $evaluatedMilestones,
        ];
    }

    public function isFinanceCleared(int $enrollmentId, ?CarbonImmutable $asOf = null): bool
    {
        return $this->evaluateEnrollment($enrollmentId, $asOf)['is_finance_cleared'] === true;
    }

    /**
     * @return array{evaluated_enrollments:int, penalties_applied:int, transitions_logged:int}
     */
    public function processOverdues(?CarbonImmutable $asOf = null): array
    {
        $evaluatedAt = $asOf ?? CarbonImmutable::now();
        $enrollmentIds = DB::table('enrollments')
            ->select('id')
            ->whereNotIn('status', ['completed'])
            ->pluck('id');

        $penaltiesApplied = 0;
        $transitionsLogged = 0;
        $evaluatedEnrollments = 0;

        foreach ($enrollmentIds as $enrollmentId) {
            $evaluation = $this->evaluateEnrollment((int) $enrollmentId, $evaluatedAt);
            $evaluatedEnrollments++;

            if ($evaluation['policy_id'] === null) {
                continue;
            }

            foreach ($evaluation['milestones'] as $milestone) {
                $previousStatus = $this->lastRecordedMilestoneStatus(
                    (int) $evaluation['enrollment_id'],
                    (int) $milestone['milestone_id'],
                );

                if ($previousStatus !== $milestone['status']) {
                    $this->recordStatusTransition(
                        (int) $evaluation['enrollment_id'],
                        (int) $evaluation['policy_id'],
                        (int) $milestone['milestone_id'],
                        $previousStatus,
                        (string) $milestone['status'],
                        $evaluatedAt,
                    );
                    $transitionsLogged++;
                }

                foreach ($milestone['overdue_cycle_keys'] as $cycleKey) {
                    if (! $this->money->greaterThanZero($milestone['penalty_amount_per_cycle'])) {
                        continue;
                    }

                    if ($this->penaltyAlreadyPosted((int) $evaluation['enrollment_id'], (int) $milestone['milestone_id'], $cycleKey)) {
                        continue;
                    }

                    $enrollment = $this->resolveEnrollment((int) $evaluation['enrollment_id']);

                    DB::transaction(function () use ($enrollment, $evaluation, $milestone, $cycleKey, $evaluatedAt): void {
                        $milestoneNames = [
                            1 => 'Midterm Installment',
                            2 => 'Final Installment',
                        ];
                        $milestoneName = $milestoneNames[$milestone['sequence']] ?? "Milestone #{$milestone['sequence']}";
                        $description = "Late Penalty - {$milestoneName}";

                        $this->postLedgerEntry(
                            $enrollment,
                            'installment_penalty',
                            $description,
                            (string) $milestone['penalty_amount_per_cycle'],
                            'installment_policy_milestone',
                            (int) $milestone['milestone_id'],
                            $evaluatedAt,
                        );

                        $this->recordPenaltyAuditLog(
                            (int) $evaluation['enrollment_id'],
                            (int) $evaluation['policy_id'],
                            (int) $milestone['milestone_id'],
                            $cycleKey,
                            (string) $milestone['penalty_amount_per_cycle'],
                            $evaluatedAt,
                        );
                    });

                    $penaltiesApplied++;
                }
            }
        }

        return [
            'evaluated_enrollments' => $evaluatedEnrollments,
            'penalties_applied' => $penaltiesApplied,
            'transitions_logged' => $transitionsLogged,
        ];
    }

    private function resolveEnrollment(int $enrollmentId): stdClass
    {
        $enrollment = DB::table('enrollments')
            ->join('student_profiles', 'student_profiles.id', '=', 'enrollments.student_profile_id')
            ->select([
                'enrollments.id',
                'enrollments.student_profile_id',
                'enrollments.term_id',
                'enrollments.year_level',
                'enrollments.enrolled_at',
                'enrollments.created_at',
                'student_profiles.program_id',
            ])
            ->where('enrollments.id', $enrollmentId)
            ->first();

        if (! $enrollment instanceof stdClass) {
            throw new RuntimeException("Enrollment {$enrollmentId} not found.");
        }

        return $enrollment;
    }

    private function resolvePolicyForEnrollment(stdClass $enrollment): ?stdClass
    {
        return DB::table('installment_policies')
            ->where('is_active', true)
            ->where(function ($query) use ($enrollment): void {
                $query->whereNull('program_id');

                if ($enrollment->program_id !== null) {
                    $query->orWhere('program_id', $enrollment->program_id);
                }
            })
            ->where(function ($query) use ($enrollment): void {
                $query->whereNull('year_level');

                if ($enrollment->year_level !== null) {
                    $query->orWhere('year_level', $enrollment->year_level);
                }
            })
            ->orderByRaw('CASE WHEN program_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByRaw('CASE WHEN year_level IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<int, stdClass>
     */
    private function milestonesForPolicy(int $policyId): array
    {
        return DB::table('installment_policy_milestones')
            ->where('installment_policy_id', $policyId)
            ->where('status', 'active')
            ->orderBy('sequence')
            ->get()
            ->all();
    }

    private function enrollmentBaseDate(stdClass $enrollment): CarbonImmutable
    {
        $source = $enrollment->enrolled_at ?? $enrollment->created_at ?? now()->toDateTimeString();

        return CarbonImmutable::parse($source, config('app.timezone'));
    }

    private function dueAt(CarbonImmutable $baseDate, int $monthOffset, string $dueDayRule): CarbonImmutable
    {
        $target = $baseDate->addMonthsNoOverflow($monthOffset);

        return match ($dueDayRule) {
            'end_of_month' => $target->endOfMonth()->endOfDay(),
            default => $target->endOfMonth()->endOfDay(),
        };
    }

    private function milestoneStatus(string $outstandingAmount, CarbonImmutable $asOf, CarbonImmutable $graceEndsAt): string
    {
        if (! $this->money->greaterThanZero($outstandingAmount)) {
            return 'paid';
        }

        if ($asOf->lessThanOrEqualTo($graceEndsAt)) {
            return 'in_grace';
        }

        return 'overdue';
    }

    /**
     * @return array<int, string>
     */
    private function overdueCycleKeys(string $status, CarbonImmutable $graceEndsAt, CarbonImmutable $asOf, string $penaltyFrequency): array
    {
        if ($status !== 'overdue') {
            return [];
        }

        if ($penaltyFrequency === 'one_time') {
            return [$graceEndsAt->format('Y-m')];
        }

        $cycles = [];
        $cursor = $graceEndsAt;

        while ($cursor->lessThanOrEqualTo($asOf)) {
            $cycles[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonthNoOverflow();
        }

        return array_values(array_unique($cycles));
    }

    private function principalAmountForEnrollment(stdClass $enrollment): string
    {
        $sum = DB::table('ledger_entries')
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('entry_type', ['assessment', 'discount'])
            ->sum('amount');

        return $this->money->normalize((string) $sum);
    }

    private function totalConfirmedPaymentsForEnrollment(stdClass $enrollment): string
    {
        $sum = DB::table('payments')
            ->where('enrollment_id', $enrollment->id)
            ->where('status', 'confirmed')
            ->sum('amount');

        return $this->money->normalize((string) $sum);
    }

    private function hasActivePromissory(stdClass $enrollment, CarbonImmutable $asOf): bool
    {
        return DB::table('promissory_notes')
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('status', ['approved', 'active'])
            ->whereDate('due_date', '>=', $asOf->toDateString())
            ->exists();
    }

    private function penaltyAlreadyPosted(int $enrollmentId, int $milestoneId, string $cycleKey): bool
    {
        return DB::table('ledger_entries')
            ->where('enrollment_id', $enrollmentId)
            ->where('entry_type', 'installment_penalty')
            ->where('reference_type', 'installment_policy_milestone')
            ->where('reference_id', $milestoneId)
            ->exists();
    }

    private function postLedgerEntry(
        stdClass $enrollment,
        string $entryType,
        string $description,
        string $amount,
        string $referenceType,
        int $referenceId,
        CarbonImmutable $postedAt,
    ): void {
        $profile = DB::table('student_profiles')
            ->where('id', $enrollment->student_profile_id)
            ->select(['id', 'current_balance'])
            ->first();

        $currentBalance = $profile instanceof stdClass
            ? $this->money->normalize((string) $profile->current_balance)
            : '0.00';

        $newBalance = $this->money->add($currentBalance, $amount);

        DB::table('ledger_entries')->insert([
            'student_profile_id' => $enrollment->student_profile_id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'entry_type' => $entryType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'amount' => $amount,
            'running_balance' => $newBalance,
            'posted_at' => $postedAt->toDateTimeString(),
            'posted_by' => null,
            'created_at' => $postedAt->toDateTimeString(),
            'updated_at' => $postedAt->toDateTimeString(),
        ]);

        DB::table('student_profiles')
            ->where('id', $enrollment->student_profile_id)
            ->update([
                'current_balance' => $newBalance,
                'updated_at' => $postedAt->toDateTimeString(),
            ]);
    }

    private function recordPenaltyAuditLog(
        int $enrollmentId,
        int $policyId,
        int $milestoneId,
        string $cycleKey,
        string $amount,
        CarbonImmutable $recordedAt
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'installment_policy',
            'description' => 'Installment overdue penalty applied.',
            'subject_type' => 'enrollment',
            'subject_id' => $enrollmentId,
            'event' => 'installment_penalty_applied',
            'causer_type' => null,
            'causer_id' => null,
            'properties' => json_encode([
                'policy_id' => $policyId,
                'milestone_id' => $milestoneId,
                'cycle' => $cycleKey,
                'amount' => $amount,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $recordedAt->toDateTimeString(),
            'updated_at' => $recordedAt->toDateTimeString(),
        ]);
    }

    private function lastRecordedMilestoneStatus(int $enrollmentId, int $milestoneId): ?string
    {
        $logs = DB::table('activity_log')
            ->where('log_name', 'installment_policy')
            ->where('event', 'installment_status_transition')
            ->where('subject_type', 'enrollment')
            ->where('subject_id', $enrollmentId)
            ->orderByDesc('id')
            ->limit(100)
            ->get(['properties']);

        foreach ($logs as $log) {
            $properties = json_decode((string) $log->properties, true);

            if (! is_array($properties)) {
                continue;
            }

            if (($properties['milestone_id'] ?? null) !== $milestoneId) {
                continue;
            }

            $toStatus = $properties['to_status'] ?? null;

            return is_string($toStatus) ? $toStatus : null;
        }

        return null;
    }

    private function recordStatusTransition(
        int $enrollmentId,
        int $policyId,
        int $milestoneId,
        ?string $fromStatus,
        string $toStatus,
        CarbonImmutable $recordedAt
    ): void {
        DB::table('activity_log')->insert([
            'log_name' => 'installment_policy',
            'description' => 'Installment milestone status transitioned.',
            'subject_type' => 'enrollment',
            'subject_id' => $enrollmentId,
            'event' => 'installment_status_transition',
            'causer_type' => null,
            'causer_id' => null,
            'properties' => json_encode([
                'policy_id' => $policyId,
                'milestone_id' => $milestoneId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $recordedAt->toDateTimeString(),
            'updated_at' => $recordedAt->toDateTimeString(),
        ]);
    }
}
