<?php

namespace Tests\Feature;

use App\Actions\Cor\BuildCorOutput;
use App\Actions\Enrollment\EnrollmentGateEvaluator;
use App\Actions\Enrollment\FinalizeOfficialEnrollment;
use App\Actions\Enrollment\StudentEnrollmentService;
use App\Models\Assessment;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use App\Models\EnrollmentSeatReservation;
use App\Models\LedgerEntry;
use App\Models\Payment;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL87DOfficialEnrollmentTest extends TestCase
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
            'student',
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->faculty = $this->staff(User::StaffRoleFaculty);
    }

    #[Test]
    public function registrar_records_official_enrollment_and_exposes_cor_source_records(): void
    {
        $fixture = $this->clearSourceGateFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $recordedAt = CarbonImmutable::parse('2026-07-06 11:00:00');

        $result = app(FinalizeOfficialEnrollment::class)->execute($fixture['enrollment'], $registrar, 'Cleared at counter.', $recordedAt);

        $this->assertSame('officially_enrolled', $result->status);
        $this->assertNotNull($result->officially_enrolled_at);

        $enrollment = $fixture['enrollment']->fresh();
        $this->assertSame('officially_enrolled', $enrollment->status);
        $this->assertNotNull($enrollment->officially_enrolled_at);

        $this->assertDatabaseHas('enrollment_gate_results', [
            'enrollment_id' => $enrollment->id,
            'gate_type' => EnrollmentGateResult::GateFinalApproval,
            'result' => EnrollmentGateResult::ResultPassed,
            'rule_version' => EnrollmentGateResult::RuleVersionTal87D,
        ]);

        // Every capacity-holding reservation is converted; none remain reserved/pending.
        $this->assertSame(2, EnrollmentSeatReservation::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', EnrollmentSeatReservation::StatusConverted)
            ->whereNotNull('converted_at')
            ->count());
        $this->assertSame(0, EnrollmentSeatReservation::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->count());

        // Official schedule bindings remain active.
        $this->assertSame(2, StudentScheduleBinding::query()
            ->whereHas('courseEnrollment', fn ($query) => $query->where('enrollment_id', $enrollment->id))
            ->where('is_active', true)
            ->count());

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => 'official_enrollment_recorded',
            'causer_id' => $registrar->id,
        ]);

        // COR / Student Hub source contract is satisfied.
        $readiness = app(StudentEnrollmentService::class)->corReadiness($enrollment);
        $this->assertTrue($readiness['ready'], 'COR readiness blockers: '.implode(',', $readiness['blockers']));

        $cor = app(BuildCorOutput::class)->forEnrollment($enrollment, $registrar, BuildCorOutput::CopyRegistrar);
        $this->assertTrue($cor['available']);
    }

    #[Test]
    public function unresolved_finance_gate_blocks_official_enrollment(): void
    {
        $fixture = $this->clearSourceGateFixture(withPostedPayment: false);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        try {
            app(FinalizeOfficialEnrollment::class)->execute($fixture['enrollment'], $registrar);
            $this->fail('Expected a ValidationException for the unresolved finance gate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('gates', $exception->errors());
        }

        $enrollment = $fixture['enrollment']->fresh();
        $this->assertNotSame('officially_enrolled', $enrollment->status);
        $this->assertNull($enrollment->officially_enrolled_at);
        $this->assertSame(0, EnrollmentSeatReservation::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', EnrollmentSeatReservation::StatusConverted)
            ->count());
    }

    #[Test]
    public function finalizing_an_already_official_enrollment_is_idempotent(): void
    {
        $fixture = $this->clearSourceGateFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $recordedAt = CarbonImmutable::parse('2026-07-06 11:00:00');

        app(FinalizeOfficialEnrollment::class)->execute($fixture['enrollment'], $registrar, null, $recordedAt);
        $firstTimestamp = $fixture['enrollment']->fresh()->officially_enrolled_at;

        app(FinalizeOfficialEnrollment::class)->execute($fixture['enrollment']->fresh(), $registrar, null, $recordedAt->addHour());
        $secondTimestamp = $fixture['enrollment']->fresh()->officially_enrolled_at;

        $this->assertNotNull($firstTimestamp);
        $this->assertTrue($firstTimestamp->equalTo($secondTimestamp), 'Official enrollment timestamp must not change on re-finalize.');
        $this->assertSame(2, EnrollmentSeatReservation::query()
            ->where('enrollment_id', $fixture['enrollment']->id)
            ->where('status', EnrollmentSeatReservation::StatusConverted)
            ->count());
        $this->assertSame(1, DB::table('activity_log')
            ->where('subject_type', Enrollment::class)
            ->where('subject_id', $fixture['enrollment']->id)
            ->where('event', 'official_enrollment_recorded')
            ->count());
    }

    #[Test]
    public function terminal_enrollment_cannot_be_officially_enrolled(): void
    {
        $fixture = $this->clearSourceGateFixture();
        $fixture['enrollment']->forceFill(['status' => 'withdrawn', 'withdrawn_at' => now()])->save();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->expectException(ValidationException::class);

        try {
            app(FinalizeOfficialEnrollment::class)->execute($fixture['enrollment'], $registrar);
        } finally {
            $enrollment = $fixture['enrollment']->fresh();
            $this->assertSame('withdrawn', $enrollment->status);
            $this->assertNull($enrollment->officially_enrolled_at);
        }
    }

    #[Test]
    public function seat_occupancy_is_conserved_when_reservation_is_converted(): void
    {
        $fixture = $this->clearSourceGateFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        /** @var Section $section */
        $section = $fixture['sections']->first();
        $offeringId = (int) $section->term_offering_id;

        $before = $this->sectionOccupancy((int) $section->id, $offeringId);
        $this->assertSame(1, $before);

        app(FinalizeOfficialEnrollment::class)->execute($fixture['enrollment'], $registrar);

        $after = $this->sectionOccupancy((int) $section->id, $offeringId);
        $this->assertSame(1, $after, 'Seat occupancy must be conserved after reservation conversion (no leak or double-count).');
        $this->assertSame(0, EnrollmentSeatReservation::query()
            ->where('section_id', $section->id)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->count());
    }

    #[Test]
    public function gate_refresh_after_official_enrollment_keeps_final_approval_passed(): void
    {
        $fixture = $this->clearSourceGateFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        app(FinalizeOfficialEnrollment::class)->execute($fixture['enrollment'], $registrar);

        app(EnrollmentGateEvaluator::class)->persist($fixture['enrollment']->fresh(), CarbonImmutable::parse('2026-07-06 12:00:00'));

        $enrollment = $fixture['enrollment']->fresh();
        $this->assertSame('officially_enrolled', $enrollment->status);
        $this->assertDatabaseHas('enrollment_gate_results', [
            'enrollment_id' => $enrollment->id,
            'gate_type' => EnrollmentGateResult::GateFinalApproval,
            'result' => EnrollmentGateResult::ResultPassed,
        ]);
    }

    #[Test]
    public function non_registrar_cannot_record_official_enrollment(): void
    {
        $fixture = $this->clearSourceGateFixture();
        $accounting = $this->staff(User::StaffRoleAccounting);

        $this->expectException(AuthorizationException::class);

        app(FinalizeOfficialEnrollment::class)->execute($fixture['enrollment'], $accounting);
    }

    private function sectionOccupancy(int $sectionId, int $termOfferingId): int
    {
        $reservations = EnrollmentSeatReservation::query()
            ->where('section_id', $sectionId)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->count();

        $bindingSeats = StudentScheduleBinding::query()
            ->where('is_active', true)
            ->whereHas('sectionMeeting.schedulingDemand', function ($query) use ($sectionId, $termOfferingId): void {
                $query
                    ->where('term_offering_id', $termOfferingId)
                    ->whereHas('sectionDeliveryGroup', fn ($groupQuery) => $groupQuery->where('section_id', $sectionId));
            })
            ->whereHas('courseEnrollment', fn ($query) => $query->where('term_offering_id', $termOfferingId))
            ->whereDoesntHave('courseEnrollment.seatReservations', function ($query) use ($sectionId): void {
                $query
                    ->where('section_id', $sectionId)
                    ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses());
            })
            ->distinct()
            ->count('course_enrollment_id');

        return $reservations + $bindingSeats;
    }

    /**
     * @return array{profile:StudentProfile,term:Term,enrollment:Enrollment,assessment:Assessment,offerings:Collection<int, TermOffering>,sections:Collection<int, Section>}
     */
    private function clearSourceGateFixture(bool $withPostedPayment = true): array
    {
        $profile = StudentProfile::factory()->create();
        // Official enrollment presupposes a completed applicant-to-student handover.
        $profile->user->update(['status' => User::StatusActive]);
        $profile->user->assignRole('student');
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
            'offerings' => collect([$first['offering'], $second['offering']]),
            'sections' => collect([$first['section'], $second['section']]),
        ];
    }

    /**
     * @return array{offering:TermOffering,section:Section}
     */
    private function publishedCoursePlacement(
        StudentProfile $profile,
        Term $term,
        Enrollment $enrollment,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
    ): array {
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
            'input_hash' => hash('sha256', uniqid('tal87d', true)),
            'solver_version' => 'tal87d-test',
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

        return ['offering' => $offering, 'section' => $section];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
