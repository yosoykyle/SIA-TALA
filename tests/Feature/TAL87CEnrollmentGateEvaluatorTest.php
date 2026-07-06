<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentGateEvaluator;
use App\Actions\Enrollment\RecordAcademicException;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\Assessment;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\EnrollmentGateResult;
use App\Models\EnrollmentSeatReservation;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL87CEnrollmentGateEvaluatorTest extends TestCase
{
    use DatabaseTransactions;

    private User $faculty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleAccounting,
            User::StaffRoleSystemSuperAdmin,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->faculty = $this->staff(User::StaffRoleFaculty);
    }

    #[Test]
    public function source_backed_gate_evaluator_persists_ready_state_without_final_official_enrollment(): void
    {
        $fixture = $this->clearSourceGateFixture();
        $checkedAt = CarbonImmutable::parse('2026-07-06 09:30:00');

        $first = app(EnrollmentGateEvaluator::class)->persist($fixture['enrollment'], $checkedAt);
        $second = app(EnrollmentGateEvaluator::class)->persist($fixture['enrollment']->fresh(), $checkedAt->addMinute());

        $this->assertCount(9, $first);
        $this->assertCount(9, $second);
        $this->assertSame(9, EnrollmentGateResult::query()->where('enrollment_id', $fixture['enrollment']->id)->count());
        $this->assertDatabaseHas('enrollment_gate_results', [
            'enrollment_id' => $fixture['enrollment']->id,
            'gate_type' => EnrollmentGateResult::GateFinalApproval,
            'result' => EnrollmentGateResult::ResultPendingReview,
            'blocker_code' => 'final_approval_pending',
            'rule_version' => EnrollmentGateResult::RuleVersionTal87C,
        ]);

        $enrollment = $fixture['enrollment']->fresh();
        $this->assertSame('ready_for_official_enrollment', $enrollment->status);
        $this->assertNull($enrollment->officially_enrolled_at);
        $this->assertSame(2, EnrollmentSeatReservation::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertSame(2, CourseEnrollment::query()->where('enrollment_id', $enrollment->id)->count());
    }

    #[Test]
    public function pending_checkout_and_under_review_evidence_do_not_clear_finance_gate_or_mutate_ledger(): void
    {
        $fixture = $this->clearSourceGateFixture(withPostedPayment: false);

        PaymentAttempt::query()->create([
            'assessment_id' => $fixture['assessment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'channel' => 'paymongo',
            'provider' => 'paymongo',
            'internal_reference' => 'TALA-PAY-PENDING-001',
            'provider_checkout_id' => 'checkout_pending_001',
            'amount' => '1500.00',
            'currency' => 'PHP',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
            'metadata' => [],
        ]);
        Payment::factory()
            ->for($fixture['profile'])
            ->for($fixture['term'])
            ->create([
                'amount' => '1500.00',
                'evidence_status' => 'under_review',
                'verified_at' => null,
                'provider_reference' => 'manual-under-review-001',
            ]);

        app(EnrollmentGateEvaluator::class)->persist($fixture['enrollment'], CarbonImmutable::parse('2026-07-06 10:00:00'));

        $this->assertDatabaseHas('enrollment_gate_results', [
            'enrollment_id' => $fixture['enrollment']->id,
            'gate_type' => EnrollmentGateResult::GateFinance,
            'result' => EnrollmentGateResult::ResultFailed,
            'blocker_code' => 'finance_not_ready',
        ]);
        $this->assertSame('pending_payment', $fixture['enrollment']->fresh()->status);
        $this->assertNull($fixture['enrollment']->fresh()->officially_enrolled_at);
        $this->assertSame(0, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    #[Test]
    public function inactive_linked_student_account_fails_identity_gate(): void
    {
        $fixture = $this->clearSourceGateFixture();
        $fixture['profile']->user->update(['status' => User::StatusInactive]);

        app(EnrollmentGateEvaluator::class)->persist($fixture['enrollment'], CarbonImmutable::parse('2026-07-06 10:30:00'));

        $this->assertDatabaseHas('enrollment_gate_results', [
            'enrollment_id' => $fixture['enrollment']->id,
            'gate_type' => EnrollmentGateResult::GateIdentity,
            'result' => EnrollmentGateResult::ResultFailed,
            'blocker_code' => 'inactive_user_account',
        ]);
        $this->assertSame('pending_review', $fixture['enrollment']->fresh()->status);
    }

    #[Test]
    public function refresh_and_typed_exception_actions_follow_staff_boundaries(): void
    {
        $fixture = $this->clearSourceGateFixture(withPostedPayment: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $accounting = $this->staff(User::StaffRoleAccounting);

        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->getRouteKey()])
            ->assertActionVisible('refreshGateResults')
            ->callAction('refreshGateResults')
            ->assertNotified('Enrollment gate results refreshed');

        Livewire::actingAs($academicHead)
            ->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->getRouteKey()])
            ->assertActionVisible('academicException')
            ->callAction('academicException', data: [
                'exception_type' => EnrollmentException::TypePrerequisite,
                'target_term_offering_id' => $fixture['offerings']->first()->id,
                'original_rule' => 'PREREQUISITE:CS101',
                'authority' => 'Academic Head Resolution 2026-07',
                'reason' => 'Approved after transcript review.',
                'evidence_reference' => 'ACAD-EX-001',
                'expires_at' => now()->addMonth()->toDateTimeString(),
            ])
            ->assertNotified('Academic exception recorded');

        $this->assertDatabaseHas('enrollment_exceptions', [
            'enrollment_id' => $fixture['enrollment']->id,
            'exception_type' => EnrollmentException::TypePrerequisite,
            'target_term_offering_id' => $fixture['offerings']->first()->id,
            'state' => EnrollmentException::StateActive,
        ]);

        Livewire::actingAs($accounting)
            ->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->getRouteKey()])
            ->assertActionHidden('refreshGateResults')
            ->assertActionHidden('academicException')
            ->assertActionHidden('unitLoadException');

        $this->expectExceptionMessage('generic gate overrides and unit-load exceptions are not accepted here');
        app(RecordAcademicException::class)->record($fixture['enrollment'], [
            'exception_type' => EnrollmentException::TypeGateOverride,
            'target_term_offering_id' => $fixture['offerings']->first()->id,
            'original_rule' => 'generic',
            'authority' => 'No authority',
            'reason' => 'Invalid generic override.',
            'evidence_reference' => 'INVALID-001',
            'expires_at' => now()->addMonth()->toDateTimeString(),
        ], $academicHead);
    }

    /**
     * @return array{profile:StudentProfile,term:Term,enrollment:Enrollment,assessment:Assessment,offerings:Collection<int, TermOffering>}
     */
    private function clearSourceGateFixture(bool $withPostedPayment = true): array
    {
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create([
            'label' => 'First Semester 2026-2027',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-10-31',
        ]);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['status' => 'pending_review']);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '5800.00',
            'discount_total' => '0.00',
            'total' => '5800.00',
            'required_downpayment' => '1500.00',
            'activated_at' => now()->subDay(),
        ]);

        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'assessment',
            'amount' => '5800.00',
            'source_type' => Assessment::class,
            'source_id' => $assessment->id,
            'description' => 'Active assessment charge',
            'posted_at' => now()->subHours(2),
            'state' => 'posted',
        ]);

        if ($withPostedPayment) {
            $payment = Payment::factory()
                ->for($profile)
                ->for($term)
                ->create([
                    'amount' => '1500.00',
                    'evidence_status' => 'verified',
                    'verified_at' => now()->subHour(),
                    'or_number' => null,
                    'provider_reference' => 'verified-payment-'.str()->uuid(),
                ]);
            LedgerEntry::query()->create([
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'direction' => LedgerEntry::DirectionPayment,
                'category' => 'payment',
                'amount' => $payment->amount,
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'payment_id' => $payment->id,
                'description' => 'Verified posted payment',
                'posted_at' => now()->subMinutes(45),
                'state' => 'posted',
            ]);
        }

        $first = $this->publishedCoursePlacement($profile, $term, $enrollment, 1, '08:00:00', '10:00:00');
        $second = $this->publishedCoursePlacement($profile, $term, $enrollment, 2, '13:00:00', '15:00:00');

        return [
            'profile' => $profile,
            'term' => $term,
            'enrollment' => $enrollment,
            'assessment' => $assessment,
            'offerings' => collect([$first, $second]),
        ];
    }

    private function publishedCoursePlacement(
        StudentProfile $profile,
        Term $term,
        Enrollment $enrollment,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
    ): TermOffering {
        $specification = CourseSpecification::factory()->create([
            'credit_units' => '3.00',
            'state' => CourseSpecification::StateActive,
        ]);
        $entry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => $specification->id,
            'term_type' => Term::TypeFirstSemester,
        ]);
        $offering = TermOffering::factory()
            ->for($term)
            ->for($entry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityOnline,
                'state' => TermOffering::StateScheduled,
            ]);
        $section = Section::factory()
            ->for($offering, 'termOffering')
            ->create([
                'capacity' => 30,
                'state' => Section::StateOpen,
            ]);
        $group = SectionDeliveryGroup::factory()
            ->for($section)
            ->create([
                'modality' => TermOffering::ModalityOnline,
                'state' => SectionDeliveryGroup::StateReady,
            ]);
        $component = CourseComponent::factory()
            ->for($specification, 'courseSpecification')
            ->create();
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'modality' => TermOffering::ModalityOnline,
                'meeting_count' => 1,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', uniqid('tal87c', true)),
            'solver_version' => 'tal87c-test',
            'published_by' => $this->faculty->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $this->faculty->id,
            'room_id' => null,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'modality' => TermOffering::ModalityOnline,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        EnrollmentSeatReservation::query()->create([
            'enrollment_id' => $enrollment->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'section_id' => $section->id,
            'status' => EnrollmentSeatReservation::StatusPending,
            'reserved_at' => now(),
            'registrar_user_id' => $this->faculty->id,
        ]);
        StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'section_meeting_id' => $meeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);

        return $offering;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
