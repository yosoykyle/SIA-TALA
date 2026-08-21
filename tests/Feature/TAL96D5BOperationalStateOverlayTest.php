<?php

namespace Tests\Feature;

use App\Actions\SystemAdministration\TAL96D5BStateCoverageMatrix;
use App\Models\ApplicantIntake;
use App\Models\Assessment;
use App\Models\ChecklistItem;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\SectionMeeting;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('acceptance-fixture')]
final class TAL96D5BOperationalStateOverlayTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->artisan('acceptance:seed-client-baseline')->assertSuccessful();
    }

    #[Test]
    public function canonical_operational_overlay_is_guarded_idempotent_and_preserves_scheduling_inputs(): void
    {
        $term = $this->presentationTerm();
        $before = $this->schedulingFingerprint($term);
        $scheduleRunCount = ScheduleGenerationRun::query()->count();
        $sectionMeetingCount = SectionMeeting::query()->count();

        $this->artisan('acceptance:seed-tal96d5b-states')
            ->expectsOutputToContain('coverage_state=PASS')
            ->expectsOutputToContain('scheduling_baseline=preserved')
            ->assertSuccessful();

        $firstCounts = $this->overlayCounts();

        $this->artisan('acceptance:seed-tal96d5b-states')->assertSuccessful();

        $this->assertSame($firstCounts, $this->overlayCounts());
        $this->assertSame($before, $this->schedulingFingerprint($term));
        $this->assertSame(54, TermOffering::query()->whereBelongsTo($term)->count());
        $this->assertSame(54, SchedulingDemand::query()->whereHas(
            'termOffering',
            fn ($query) => $query->whereBelongsTo($term),
        )->count());
        $this->assertSame($scheduleRunCount, ScheduleGenerationRun::query()->count());
        $this->assertSame($sectionMeetingCount, SectionMeeting::query()->count());

        $applicant = User::query()->where('email', 'applicant.demo@example.test')->sole();
        $this->assertEqualsCanonicalizing(
            [ApplicantIntake::StatusDraft, ApplicantIntake::StatusWithdrawn],
            ApplicantIntake::query()->whereBelongsTo($applicant)->pluck('status')->all(),
        );

        $reviewApplicant = User::query()->where('email', 'applicant.review.demo@example.test')->sole();
        $reviewIntake = ApplicantIntake::query()
            ->whereBelongsTo($reviewApplicant)
            ->where('status', ApplicantIntake::StatusPending)
            ->sole();
        $this->assertEqualsCanonicalizing(
            ['FORM_137', 'IDENTITY_DOCUMENT'],
            ChecklistItem::query()
                ->whereBelongsTo($reviewIntake)
                ->whereIn('requirement_type', ['FORM_137', 'IDENTITY_DOCUMENT'])
                ->pluck('requirement_type')
                ->all(),
        );
    }

    #[Test]
    public function canonical_overlay_exposes_named_enrollment_finance_grade_and_lifecycle_states(): void
    {
        $this->artisan('acceptance:seed-tal96d5b-states')->assertSuccessful();

        $this->assertEnrollmentState('DBM-2A-001', 'pending', Enrollment::SelectionIndividuallyAdvised, Enrollment::OutcomeInProgress);
        $this->assertEnrollmentState('DIT-1A-001', 'pending_payment', Enrollment::SelectionStandardCurriculum, Enrollment::OutcomeInProgress);
        $this->assertEnrollmentState('DIT-1A-002', 'pending_payment', Enrollment::SelectionStandardCurriculum, Enrollment::OutcomeInProgress);
        $this->assertEnrollmentState('DIT-2A-001', 'pending_payment', Enrollment::SelectionStandardCurriculum, Enrollment::OutcomeInProgress);
        $this->assertEnrollmentState('DTHM-1A-001', 'cancelled', Enrollment::SelectionStandardCurriculum, Enrollment::OutcomeCancelled);

        $dueEnrollment = $this->enrollmentFor('DIT-1A-001');
        $partialEnrollment = $this->enrollmentFor('DIT-1A-002');
        $clearedEnrollment = $this->enrollmentFor('DIT-2A-001');

        $this->assertSame(Assessment::StateActive, Assessment::query()->whereBelongsTo($dueEnrollment)->sole()->state);
        $this->assertSame(Assessment::StateActive, Assessment::query()->whereBelongsTo($partialEnrollment)->sole()->state);
        $this->assertSame(Assessment::StateActive, Assessment::query()->whereBelongsTo($clearedEnrollment)->sole()->state);
        $this->assertContains(
            Payment::query()->where('student_profile_id', $dueEnrollment->student_profile_id)->count(),
            [0, 1],
            'The due persona may have one retained provider-gate payment after PayMongo acceptance.',
        );
        $this->assertSame('1000.00', Payment::query()
            ->where('provider_reference', 'PAYMENT-PARTIAL-001')
            ->sole()
            ->amount);
        $this->assertSame('2000.00', Payment::query()
            ->where('provider_reference', 'PAYMENT-CLEARED-001')
            ->sole()
            ->amount);
        $this->assertSame('failed', PaymentAttempt::query()
            ->where('internal_reference', 'CHECKOUT-FAILED-001')
            ->sole()
            ->status);
        $this->assertSame('pending', PaymentAttempt::query()
            ->where('internal_reference', 'CHECKOUT-PENDING-001')
            ->sole()
            ->status);

        $this->assertEqualsCanonicalizing(
            [
                GradeRoster::StateDraft,
                GradeRoster::StateSubmitted,
                GradeRoster::StateReturned,
                GradeRoster::StateReleased,
            ],
            GradeRoster::query()->distinct()->pluck('state')->all(),
        );
        $this->assertSame(2, StudentLifecycleChange::query()
            ->whereIn('private_source_reference', [
                'LIFECYCLE-WITHDRAWAL-001',
                'LIFECYCLE-PROGRAM-SHIFT-001',
            ])
            ->count());
    }

    #[Test]
    public function coverage_matrix_names_every_required_role_and_state_family_without_claiming_external_proof(): void
    {
        $this->artisan('acceptance:seed-tal96d5b-states')->assertSuccessful();

        $report = app(TAL96D5BStateCoverageMatrix::class)->report();

        $this->assertSame('PASS', $report['coverage_state']);
        $this->assertEqualsCanonicalizing(
            [
                'public',
                'applicant',
                'student',
                'registrar',
                'accounting',
                'faculty',
                'academic_head',
                'system_super_admin',
            ],
            array_keys($report['roles']),
        );
        $this->assertEqualsCanonicalizing(
            [
                'applicant',
                'academic',
                'document',
                'enrollment',
                'finance',
                'payment',
                'scheduling',
                'grade',
                'lifecycle',
                'failure_recovery',
            ],
            array_keys($report['state_families']),
        );

        foreach ($report['state_families'] as $family) {
            $this->assertNotSame('', $family['persona']);
            $this->assertNotSame('', $family['evidence']);
            $this->assertContains(
                $family['disposition'],
                ['fixture_record', 'programmatic_test', 'human_gate'],
            );
        }

        $this->assertSame('human_gate', $report['state_families']['scheduling']['disposition']);
        $this->assertSame('human_gate', $report['state_families']['payment']['disposition']);
        $this->assertStringContainsString(
            'does not claim',
            $report['state_families']['payment']['evidence'],
        );
    }

    private function presentationTerm(): Term
    {
        $this->assertSame(47, StudentProfile::query()->count());

        return Term::query()
            ->where('label', 'Second Semester')
            ->whereHas('academicYear', fn ($query) => $query->where('label', 'AY 2025-2026'))
            ->sole();
    }

    private function schedulingFingerprint(Term $term): string
    {
        return hash('sha256', SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->orderBy('id')
            ->get([
                'id',
                'term_offering_id',
                'course_component_id',
                'section_delivery_group_id',
                'demand_key',
                'required_duration_minutes',
                'meeting_count',
                'modality',
                'fixed_faculty_user_id',
                'fixed_room_id',
                'fixed_day_of_week',
                'fixed_start_time',
                'source_snapshot',
                'readiness_findings',
                'validation_state',
                'generated_by',
                'readiness_checked_at',
            ])
            ->toJson());
    }

    /**
     * @return array<string, int>
     */
    private function overlayCounts(): array
    {
        return [
            'enrollments' => Enrollment::query()->count(),
            'assessments' => Assessment::query()->count(),
            'payments' => Payment::query()->count(),
            'payment_attempts' => PaymentAttempt::query()->count(),
            'grade_rosters' => GradeRoster::query()->count(),
            'lifecycle_changes' => StudentLifecycleChange::query()->count(),
            'applicant_intakes' => ApplicantIntake::query()->count(),
            'checklist_items' => ChecklistItem::query()->count(),
        ];
    }

    private function assertEnrollmentState(string $studentNumber, string $status, string $selectionBasis, string $outcome): void
    {
        $enrollment = $this->enrollmentFor($studentNumber);

        $this->assertSame($status, $enrollment->status);
        $this->assertSame($selectionBasis, $enrollment->selection_basis);
        $this->assertSame($outcome, $enrollment->canonical_outcome);
    }

    private function enrollmentFor(string $studentNumber): Enrollment
    {
        return Enrollment::query()
            ->whereHas('studentProfile', fn ($query) => $query->where('student_number', $studentNumber))
            ->whereBelongsTo($this->presentationTerm(), 'term')
            ->sole();
    }
}
