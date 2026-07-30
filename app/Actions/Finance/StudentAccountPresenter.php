<?php

namespace App\Actions\Finance;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Scoped;
use RuntimeException;

#[Scoped]
final class StudentAccountPresenter
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly FinanceEvidenceService $financeEvidence,
        private readonly EnrollmentFinanceClearanceService $financeClearance,
    ) {}

    /**
     * @return array{
     *     student_number:string,
     *     student_name:string,
     *     program:string,
     *     year_level:string,
     *     section:string,
     *     term:string,
     *     assessment_state:string,
     *     assessment_total:string,
     *     posted_payments:string,
     *     remaining_balance:string,
     *     current_due:string,
     *     current_due_source:string,
     *     payment_status:string,
     *     finance_gate_status:string,
     *     finance_gate_source:string,
     *     responsible_office:string,
     *     next_action:string,
     *     verification_status:string,
     *     or_mapping_state:string,
     *     charge_lines:mixed,
     *     schedule_rows:mixed,
     *     attempt_rows:mixed,
     *     acknowledgement_rows:mixed,
     *     allocation_rows:mixed,
     *     ledger_rows:mixed,
     *     adjustment_rows:mixed,
     *     accommodation_summary:mixed
     * }
     */
    public function present(Assessment $assessment, User $actor): array
    {
        $cacheKey = $actor->getKey().':'.$assessment->getKey();

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $evidence = $this->financeEvidence->financeForAssessment(
            $assessment,
            $actor,
            FinanceEvidenceService::CopyAccounting,
        );
        $enrollment = $evidence['enrollment'] ?? null;
        $studentProfile = $evidence['student_profile'] ?? null;

        if (! $enrollment instanceof Enrollment || ! $studentProfile instanceof StudentProfile) {
            throw new RuntimeException('The assessment is missing its enrollment or student account owner.');
        }

        /** @var array<string, mixed> $summary */
        $summary = $evidence['summary'];
        /** @var array<string, mixed> $state */
        $state = $evidence['state'];
        /** @var array<string, string> $paymentEvidence */
        $paymentEvidence = $state['payment_evidence'];
        $readiness = $this->financeClearance->readiness(
            $enrollment,
            $studentProfile,
            (string) $summary['balance'],
            CarbonImmutable::now(config('app.timezone')),
        );

        $ledgerRows = collect($state['ledger_rows']);

        return $this->cache[$cacheKey] = [
            'student_number' => (string) $summary['student_number'],
            'student_name' => (string) $summary['student_name'],
            'program' => (string) ($summary['program'] ?? 'Not recorded'),
            'year_level' => (string) ($summary['year_level'] ?? 'Not recorded'),
            'section' => (string) ($summary['section'] ?? 'Not assigned'),
            'term' => (string) $summary['term'],
            'assessment_state' => str((string) $assessment->state)->replace('_', ' ')->headline()->toString(),
            'assessment_total' => (string) $state['assessment_total'],
            'posted_payments' => (string) $state['posted_payments'],
            'remaining_balance' => (string) $state['ledger_balance'],
            'current_due' => (string) $state['current_due'],
            'current_due_source' => (string) $state['current_due_source'],
            'payment_status' => (string) $state['payment_status'],
            'finance_gate_status' => $readiness['finance_cleared'] ? 'Cleared' : 'Blocked',
            'finance_gate_source' => $this->clearanceSourceLabel($readiness['finance_clearance_source']),
            'responsible_office' => $paymentEvidence['responsible_office'],
            'next_action' => $paymentEvidence['required_action'],
            'verification_status' => (string) $state['verification_status'],
            'or_mapping_state' => (string) $state['or_mapping_state'],
            'charge_lines' => $state['charge_lines'],
            'schedule_rows' => $state['schedule_rows'],
            'attempt_rows' => $state['attempt_rows'],
            'acknowledgement_rows' => $state['acknowledgement_rows'],
            'allocation_rows' => $state['allocation_rows'],
            'ledger_rows' => $ledgerRows->all(),
            'adjustment_rows' => $ledgerRows
                ->filter(fn (array $row): bool => in_array($row['direction'], ['Adjustment', 'Reversal'], true))
                ->values()
                ->all(),
            'accommodation_summary' => $state['accommodation_summary'],
        ];
    }

    private function clearanceSourceLabel(string $source): string
    {
        return match ($source) {
            'cleared_balance' => 'No remaining balance',
            'posted_ledger_payment' => 'Posted payment meets the required minimum',
            'active_financial_accommodation' => 'Active approved financial accommodation',
            default => 'Required payment or approved accommodation is still missing',
        };
    }
}
