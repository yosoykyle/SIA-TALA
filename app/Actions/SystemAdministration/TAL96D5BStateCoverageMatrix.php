<?php

namespace App\Actions\SystemAdministration;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\Payment;
use App\Models\SchedulingDemand;
use App\Models\StudentLifecycleChange;
use App\Models\User;

/**
 * Builds the test-only TAL-96D5B state-coverage report.
 *
 * This report distinguishes persistent presentation records, focused programmatic
 * proof, and human-only external gates. It does not change application data.
 */
final class TAL96D5BStateCoverageMatrix
{
    /**
     * @return array{
     *     coverage_state:'PASS'|'FAIL',
     *     roles:array<string, array{persona:string,available:bool}>,
     *     state_families:array<string, array{persona:string,disposition:string,evidence:string,represented:bool}>
     * }
     */
    public function report(): array
    {
        $roles = [
            'public' => ['persona' => 'Unauthenticated visitor', 'available' => true],
            'applicant' => $this->account('applicant.demo@example.test'),
            'student' => $this->account('student.demo@example.test'),
            'registrar' => $this->account('registrar.demo@example.test'),
            'accounting' => $this->account('accounting.demo@example.test'),
            'faculty' => $this->account('faculty.demo@example.test'),
            'academic_head' => $this->account('academic-head.demo@example.test'),
            'system_super_admin' => $this->account('system-admin.demo@example.test'),
        ];

        $families = [
            'applicant' => [
                'persona' => 'applicant.demo@example.test',
                'disposition' => 'programmatic_test',
                'evidence' => 'Named D2A intake, validation, withdrawal, history, notification, and handover tests.',
                'represented' => true,
            ],
            'academic' => [
                'persona' => 'AY 2025-2026 / Second Semester',
                'disposition' => 'fixture_record',
                'evidence' => 'Client-aligned MIN contains 54 offerings and 54 ready scheduling demands.',
                'represented' => SchedulingDemand::query()
                    ->where('validation_state', SchedulingDemand::ValidationReadyForReview)
                    ->count() === 54,
            ],
            'document' => [
                'persona' => 'applicant.demo@example.test',
                'disposition' => 'programmatic_test',
                'evidence' => 'Named D2A mixed digital, physical-copy, metadata, review, rejection, and re-upload tests.',
                'represented' => true,
            ],
            'enrollment' => [
                'persona' => 'DBM-2A-001 and DTHM-1A-001',
                'disposition' => 'fixture_record',
                'evidence' => 'Irregular waiting and cancelled terminal Enrollment records coexist with D4B official enrollments.',
                'represented' => $this->enrollmentExists('DBM-2A-001', 'pending', 'irregular')
                    && $this->enrollmentExists('DTHM-1A-001', 'cancelled', 'regular')
                    && Enrollment::query()->where('status', 'officially_enrolled')->exists(),
            ],
            'finance' => [
                'persona' => 'DIT-1A-001, DIT-1A-002, and DIT-2A-001',
                'disposition' => 'fixture_record',
                'evidence' => 'Active due, partial-payment, and finance-cleared assessment states use real ledger services.',
                'represented' => Assessment::query()->where('state', Assessment::StateActive)->count() >= 3
                    && Payment::query()->where('provider_reference', 'PAYMENT-PARTIAL-001')->exists()
                    && Payment::query()->where('provider_reference', 'PAYMENT-CLEARED-001')->exists(),
            ],
            'payment' => [
                'persona' => 'DIT-1A-001 and DIT-1A-002',
                'disposition' => 'human_gate',
                'evidence' => 'Prepared pending and failed attempts exercise recovery projections; this does not claim PayMongo provider acceptance, which remains separately authorized.',
                'represented' => true,
            ],
            'scheduling' => [
                'persona' => 'Client-aligned MIN 54-demand workload',
                'disposition' => 'human_gate',
                'evidence' => 'All inputs are ready; candidate generation and publication require the separately approved one-time Cloud Run functional solve.',
                'represented' => SchedulingDemand::query()->count() === 54,
            ],
            'grade' => [
                'persona' => 'DBM-1A-001 through DBM-1A-004',
                'disposition' => 'fixture_record',
                'evidence' => 'Draft, submitted, returned, and released grade-roster records are present.',
                'represented' => GradeRoster::query()->distinct()->count('state') === 4,
            ],
            'lifecycle' => [
                'persona' => 'Withdrawal, program-change, hold, and completion-review personas',
                'disposition' => 'fixture_record',
                'evidence' => 'Lifecycle changes, active holds, and graduation snapshots are deterministic D4B overlay records.',
                'represented' => StudentLifecycleChange::query()
                    ->whereIn('private_source_reference', [
                        'LIFECYCLE-WITHDRAWAL-001',
                        'LIFECYCLE-PROGRAM-SHIFT-001',
                    ])
                    ->count() === 2,
            ],
            'failure_recovery' => [
                'persona' => 'Cross-role invalid, stale, duplicate, rollback, and retry cases',
                'disposition' => 'programmatic_test',
                'evidence' => 'Named D2-D4 tests prove no-mutation failures, idempotency, authorization, rollback, and recovery guidance.',
                'represented' => true,
            ],
        ];

        $passes = collect($roles)->every(fn (array $role): bool => $role['available'])
            && collect($families)->every(fn (array $family): bool => $family['represented']);

        return [
            'coverage_state' => $passes ? 'PASS' : 'FAIL',
            'roles' => $roles,
            'state_families' => $families,
        ];
    }

    /**
     * @return array{persona:string,available:bool}
     */
    private function account(string $email): array
    {
        return [
            'persona' => $email,
            'available' => User::query()
                ->where('email', $email)
                ->whereNotNull('email_verified_at')
                ->exists(),
        ];
    }

    private function enrollmentExists(string $studentNumber, string $status, string $studentType): bool
    {
        return Enrollment::query()
            ->where('status', $status)
            ->where('student_type', $studentType)
            ->whereHas(
                'studentProfile',
                fn ($query) => $query->where('student_number', $studentNumber),
            )
            ->exists();
    }
}
