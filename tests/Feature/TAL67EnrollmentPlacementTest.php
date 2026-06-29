<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentPlacementService;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\CandidateScheduleRow;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use App\Models\EnrollmentSeatReservation;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL67EnrollmentPlacementTest extends TestCase
{
    use DatabaseTransactions;

    private EnrollmentPlacementService $placement;

    private User $faculty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->placement = app(EnrollmentPlacementService::class);
        $this->faculty = $this->staff(User::StaffRoleFaculty);
    }

    public function test_registrar_confirms_placement_into_published_schedule_without_finance_or_cor_side_effects(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($term)->create(['status' => 'capacity_pending']);
        $section = $this->publishedPlacement($term, capacity: 2, meetingCount: 2)['section'];

        $summary = $this->placement->confirm($enrollment, $section->id, $registrar);

        $this->assertFalse($summary['already_confirmed']);
        $this->assertSame(1, CourseEnrollment::query()->count());
        $this->assertSame(1, EnrollmentSeatReservation::query()->count());
        $this->assertSame(2, StudentScheduleBinding::query()->count());
        $this->assertSame(3, EnrollmentGateResult::query()->count());

        $courseEnrollment = CourseEnrollment::query()->sole();
        $reservation = EnrollmentSeatReservation::query()->sole();

        $this->assertSame($enrollment->id, $courseEnrollment->enrollment_id);
        $this->assertSame($section->term_offering_id, $courseEnrollment->term_offering_id);
        $this->assertSame(CourseEnrollment::StatusActive, $courseEnrollment->status);
        $this->assertSame($courseEnrollment->id, $reservation->course_enrollment_id);
        $this->assertSame($section->id, $reservation->section_id);
        $this->assertSame(EnrollmentSeatReservation::StatusPending, $reservation->status);
        $this->assertSame($registrar->id, $reservation->registrar_user_id);

        $this->assertSame(
            [
                EnrollmentGateResult::GateCapacity,
                EnrollmentGateResult::GateConflict,
                EnrollmentGateResult::GatePlacement,
            ],
            EnrollmentGateResult::query()->orderBy('gate_type')->pluck('gate_type')->sort()->values()->all(),
        );
        $this->assertTrue(EnrollmentGateResult::query()->where('result', EnrollmentGateResult::ResultPassed)->count() === 3);
        $this->assertSame('pending_payment', $enrollment->fresh()->status);
        $this->assertNotNull($enrollment->fresh()->registered_at);

        $this->assertSame(0, DB::table('ledger_entries')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('payment_attempts')->count());
        $this->assertSame(0, DB::table('output_access_logs')->count());
    }

    public function test_system_super_admin_can_confirm_placement(): void
    {
        $systemSuperAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($term)->create();
        $section = $this->publishedPlacement($term)['section'];

        $this->assertTrue(Gate::forUser($systemSuperAdmin)->allows('confirmPlacement', $enrollment));

        $this->placement->confirm($enrollment, $section->id, $systemSuperAdmin);

        $this->assertSame($systemSuperAdmin->id, EnrollmentSeatReservation::query()->sole()->registrar_user_id);
    }

    public function test_academic_head_and_unauthorized_staff_cannot_confirm_placement(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->faculty;
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($term)->create();
        $section = $this->publishedPlacement($term)['section'];

        $this->assertFalse(Gate::forUser($academicHead)->allows('confirmPlacement', $enrollment));
        $this->assertFalse(Gate::forUser($faculty)->allows('confirmPlacement', $enrollment));

        foreach ([$academicHead, $faculty] as $actor) {
            try {
                $this->placement->confirm($enrollment, $section->id, $actor);
                $this->fail('Unauthorized placement was not rejected.');
            } catch (AuthorizationException) {
                $this->assertSame(0, CourseEnrollment::query()->count());
                $this->assertSame(0, EnrollmentSeatReservation::query()->count());
                $this->assertSame(0, StudentScheduleBinding::query()->count());
            }
        }
    }

    public function test_candidate_unpublished_and_superseded_rows_cannot_be_selected(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($term)->create();
        $candidateOnly = $this->candidateOnlyPlacement($term);
        $superseded = $this->publishedPlacement($term, runStatus: ScheduleGenerationRun::StatusSuperseded)['section'];

        foreach ([$candidateOnly, $superseded] as $section) {
            try {
                $this->placement->confirm($enrollment, $section->id, $registrar);
                $this->fail('Non-published placement was not rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('section_id', $exception->errors());
                $this->assertSame(0, CourseEnrollment::query()->count());
                $this->assertSame(0, EnrollmentSeatReservation::query()->count());
                $this->assertSame(0, StudentScheduleBinding::query()->count());
            }
        }
    }

    public function test_duplicate_confirmation_is_idempotent_without_duplicate_active_records(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($term)->create();
        $section = $this->publishedPlacement($term, meetingCount: 2)['section'];

        $this->placement->confirm($enrollment, $section->id, $registrar);
        $second = $this->placement->confirm($enrollment, $section->id, $registrar);

        $this->assertTrue($second['already_confirmed']);
        $this->assertSame(1, CourseEnrollment::query()->count());
        $this->assertSame(1, EnrollmentSeatReservation::query()->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->count());
        $this->assertSame(2, StudentScheduleBinding::query()->where('is_active', true)->count());
        $this->assertSame(3, EnrollmentGateResult::query()->count());
    }

    public function test_capacity_blocks_final_seat_and_rolls_back_partial_writes(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $filledEnrollment = Enrollment::factory()->for($term)->create();
        $blockedEnrollment = Enrollment::factory()->for($term)->create();
        $section = $this->publishedPlacement($term, capacity: 1)['section'];

        $this->placement->confirm($filledEnrollment, $section->id, $registrar);

        try {
            $this->placement->confirm($blockedEnrollment, $section->id, $registrar);
            $this->fail('Full placement was not rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
        }

        $this->assertSame(1, CourseEnrollment::query()->count());
        $this->assertSame(1, EnrollmentSeatReservation::query()->count());
        $this->assertSame(1, StudentScheduleBinding::query()->count());
        $this->assertFalse(CourseEnrollment::query()->where('enrollment_id', $blockedEnrollment->id)->exists());
    }

    public function test_student_time_conflict_is_rejected_and_rolls_back_partial_writes(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($term)->create();
        $existing = $this->publishedPlacement($term, startsAt: '09:00:00', endsAt: '11:00:00');
        $target = $this->publishedPlacement($term, startsAt: '10:00:00', endsAt: '12:00:00');
        $existingCourseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $existing['section']->term_offering_id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $existingCourseEnrollment->id,
            'section_meeting_id' => $existing['meetings']->first()->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);

        try {
            $this->placement->confirm($enrollment, $target['section']->id, $registrar);
            $this->fail('Conflicting placement was not rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('conflict', $exception->errors());
        }

        $this->assertSame(1, CourseEnrollment::query()->count());
        $this->assertSame(0, EnrollmentSeatReservation::query()->count());
        $this->assertSame(1, StudentScheduleBinding::query()->where('is_active', true)->count());
    }

    public function test_existing_different_active_reservation_is_blocked_for_mvp(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($term)->create();
        $reserved = $this->publishedPlacement($term);
        $target = $this->publishedPlacement($term, startsAt: '13:00:00', endsAt: '15:00:00');
        $existingCourseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $reserved['section']->term_offering_id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        EnrollmentSeatReservation::query()->create([
            'enrollment_id' => $enrollment->id,
            'course_enrollment_id' => $existingCourseEnrollment->id,
            'section_id' => $reserved['section']->id,
            'status' => EnrollmentSeatReservation::StatusPending,
            'reserved_at' => now(),
            'registrar_user_id' => $registrar->id,
        ]);

        try {
            $this->placement->confirm($enrollment, $target['section']->id, $registrar);
            $this->fail('Different active placement reservation was not blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('section_id', $exception->errors());
        }

        $this->assertSame(1, CourseEnrollment::query()->count());
        $this->assertSame(1, EnrollmentSeatReservation::query()->where('status', EnrollmentSeatReservation::StatusPending)->count());
        $this->assertSame(0, StudentScheduleBinding::query()->count());
    }

    public function test_filament_action_visibility_and_confirmation_behavior_follow_policy(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($term)->create();
        $section = $this->publishedPlacement($term)['section'];

        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertActionVisible('confirmPlacement')
            ->callAction('confirmPlacement', data: ['section_id' => $section->id])
            ->assertNotified('Placement confirmed');

        Livewire::actingAs($academicHead)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->assertActionHidden('confirmPlacement');
    }

    /**
     * @return array{section:Section,offering:TermOffering,demand:SchedulingDemand,run:ScheduleGenerationRun,meetings:Collection<int, SectionMeeting>}
     */
    private function publishedPlacement(
        Term $term,
        int $capacity = 30,
        int $meetingCount = 1,
        string $runStatus = ScheduleGenerationRun::StatusPublished,
        string $startsAt = '08:00:00',
        string $endsAt = '10:00:00',
    ): array {
        $offering = TermOffering::factory()->for($term)->create([
            'modality' => TermOffering::ModalityOnline,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'capacity' => $capacity,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'modality' => TermOffering::ModalityOnline,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $component = CourseComponent::factory()->create();
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'modality' => TermOffering::ModalityOnline,
                'meeting_count' => $meetingCount,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $runStatus,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', uniqid('tal67', true)),
            'solver_version' => 'tal67-test',
            'published_by' => $runStatus === ScheduleGenerationRun::StatusPublished ? $this->staff(User::StaffRoleRegistrar)->id : null,
            'published_at' => $runStatus === ScheduleGenerationRun::StatusPublished ? now() : null,
            'publication_version' => $runStatus === ScheduleGenerationRun::StatusPublished ? 1 : null,
        ]);
        $meetings = collect();

        for ($sequence = 1; $sequence <= $meetingCount; $sequence++) {
            $meetings->push(SectionMeeting::query()->create([
                'schedule_run_id' => $run->id,
                'scheduling_demand_id' => $demand->id,
                'meeting_sequence' => $sequence,
                'faculty_user_id' => $this->faculty->id,
                'room_id' => null,
                'day_of_week' => $sequence,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'modality' => TermOffering::ModalityOnline,
                'state' => SectionMeeting::StateActive,
                'published_at' => now(),
            ]));
        }

        return [
            'section' => $section,
            'offering' => $offering,
            'demand' => $demand,
            'run' => $run,
            'meetings' => $meetings,
        ];
    }

    private function candidateOnlyPlacement(Term $term): Section
    {
        $placement = $this->publishedPlacement($term, runStatus: ScheduleGenerationRun::StatusUnderReview);
        SectionMeeting::query()->delete();
        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $placement['run']->id,
            'scheduling_demand_id' => $placement['demand']->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $this->faculty->id,
            'room_id' => null,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        return $placement['section'];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
