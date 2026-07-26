<?php

namespace Tests\Feature;

use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ListScheduleGenerationRuns;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\SchedulingDemands\Pages\ListSchedulingDemands;
use App\Filament\Student\Pages\ScheduleView;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\Room;
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
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D3AMasterScheduleFunctionalHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function scheduling_authority_keeps_registrar_mutations_academic_head_read_only_and_system_admin_on_integration_operations(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $systemAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $run = $this->createRun($registrar);

        $this->assertTrue(Gate::forUser($registrar)->allows('viewAny', SchedulingDemand::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('create', SchedulingDemand::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('create', ScheduleGenerationRun::class));
        $this->assertTrue(Gate::forUser($registrar)->allows('reviewCandidates', $run));

        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAny', SchedulingDemand::class));
        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($academicHead)->allows('create', SchedulingDemand::class));
        $this->assertFalse(Gate::forUser($academicHead)->allows('create', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($academicHead)->allows('reviewCandidates', $run));

        $this->assertFalse(Gate::forUser($systemAdmin)->allows('viewAny', SchedulingDemand::class));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('create', SchedulingDemand::class));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('viewAny', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('create', ScheduleGenerationRun::class));
        $this->assertFalse(Gate::forUser($systemAdmin)->allows('reviewCandidates', $run));
    }

    #[Test]
    public function unexpected_generation_and_dispatch_failures_do_not_disclose_internal_exception_messages(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        $this->app->instance(GenerateSchedulingDemand::class, new class extends GenerateSchedulingDemand
        {
            public function forTerm(User $actor, Term $term): array
            {
                throw new RuntimeException('C:\\private\\solver-token.json');
            }
        });

        Livewire::actingAs($registrar)
            ->test(ListSchedulingDemands::class)
            ->callAction(TestAction::make('generateForTerm')->table(), ['term_id' => $term->id])
            ->assertNotified(
                Notification::make()
                    ->title('Scheduling demand generation failed')
                    ->body('TALA could not generate scheduling demand. Try again or ask the System Administrator to review the application log.')
                    ->danger()
                    ->persistent(),
            )
            ->assertDontSee('solver-token.json');

        $this->app->instance(
            ScheduleGenerationService::class,
            new class(app(ScheduleSolverSnapshotService::class)) extends ScheduleGenerationService
            {
                public function generate(Term $term, User $registrar): ScheduleGenerationRun
                {
                    throw new RuntimeException('https://private-solver.example.test');
                }
            },
        );

        Livewire::actingAs($registrar)
            ->test(ListScheduleGenerationRuns::class)
            ->callAction('dispatchSolverRun', data: ['term_id' => $term->id])
            ->assertNotified(
                Notification::make()
                    ->title('Solver run failed')
                    ->body('TALA could not queue the solver run. Try again or ask the System Administrator to review the application log.')
                    ->danger()
                    ->persistent(),
            )
            ->assertDontSee('private-solver.example.test');
    }

    #[Test]
    public function expected_readiness_and_duplicate_run_validation_remains_actionable(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        $this->app->instance(GenerateSchedulingDemand::class, new class extends GenerateSchedulingDemand
        {
            public function forTerm(User $actor, Term $term): array
            {
                throw ValidationException::withMessages([
                    'term_id' => 'Complete the Term scheduling window before generating demand.',
                ]);
            }
        });

        Livewire::actingAs($registrar)
            ->test(ListSchedulingDemands::class)
            ->callAction(TestAction::make('generateForTerm')->table(), ['term_id' => $term->id])
            ->assertNotified(
                Notification::make()
                    ->title('Scheduling demand generation blocked')
                    ->body('Complete the Term scheduling window before generating demand.')
                    ->danger()
                    ->persistent(),
            );

        $this->app->instance(
            ScheduleGenerationService::class,
            new class(app(ScheduleSolverSnapshotService::class)) extends ScheduleGenerationService
            {
                public function generate(Term $term, User $registrar): ScheduleGenerationRun
                {
                    throw ValidationException::withMessages([
                        'term_id' => 'Another queued or dispatching solver run already exists for this term.',
                    ]);
                }
            },
        );

        Livewire::actingAs($registrar)
            ->test(ListScheduleGenerationRuns::class)
            ->callAction('dispatchSolverRun', data: ['term_id' => $term->id])
            ->assertNotified(
                Notification::make()
                    ->title('Solver run blocked')
                    ->body('Another queued or dispatching solver run already exists for this term.')
                    ->danger()
                    ->persistent(),
            );
    }

    #[Test]
    public function run_view_explains_solution_quality_without_presenting_predictive_accuracy(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $run = $this->createRun($registrar, [
            'solver_result' => [
                'solver_status' => 'feasible',
                'summary' => [
                    'assigned_count' => 18,
                    'unassigned_count' => 2,
                    'hard_violation_count' => 0,
                    'warning_count' => 1,
                ],
                'solver_statistics' => [
                    'objective_value' => 12.5,
                    'best_objective_bound' => 10.0,
                    'relative_optimality_gap' => 0.2,
                    'wall_time_seconds' => 8.75,
                ],
            ],
        ]);

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $run->getRouteKey()])
            ->assertOk()
            ->assertSee('Solution Quality')
            ->assertSee('Feasible')
            ->assertSee('18 of 20 demands assigned')
            ->assertSee('The schedule satisfies the validated hard constraints, but the solver did not prove it was the best possible result within the time limit.')
            ->assertSee('20.00%')
            ->assertSee('8.75 seconds')
            ->assertSee('optimization-quality measures')
            ->assertSee('not predictive accuracy or an accuracy score');
    }

    #[Test]
    public function faculty_and_student_can_print_only_their_current_official_schedule_and_access_is_logged(): void
    {
        $fixture = $this->scheduleFixture();
        $other = $this->scheduleFixture();

        Livewire::actingAs($fixture['faculty'])
            ->test(FacultySchedule::class)
            ->assertActionExists('printSchedule');

        $this->actingAs($fixture['faculty'])
            ->get(route('faculty.schedule.print'))
            ->assertOk()
            ->assertSee($fixture['course']->code)
            ->assertDontSee($other['course']->code)
            ->assertSee('Print / Save as PDF');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => 'FACULTY_SCHEDULE',
            'source_record_type' => User::class,
            'source_record_id' => $fixture['faculty']->id,
            'actor_user_id' => $fixture['faculty']->id,
            'actor_role' => User::StaffRoleFaculty,
            'action' => 'PRINT',
            'copy_context' => 'FACULTY_COPY',
            'row_count' => 1,
            'status' => 'logged',
        ]);

        Livewire::actingAs($fixture['student'])
            ->test(ScheduleView::class)
            ->assertActionExists('printSchedule');

        $this->actingAs($fixture['student'])
            ->get(route('student.schedule.print'))
            ->assertOk()
            ->assertSee($fixture['course']->code)
            ->assertDontSee($other['course']->code)
            ->assertSee('Print / Save as PDF');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => 'STUDENT_SCHEDULE',
            'source_record_type' => Enrollment::class,
            'source_record_id' => $fixture['enrollment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'actor_user_id' => $fixture['student']->id,
            'actor_role' => 'student',
            'action' => 'PRINT',
            'copy_context' => 'STUDENT_COPY',
            'row_count' => 1,
            'status' => 'logged',
        ]);

        $this->actingAs($other['student'])
            ->get(route('faculty.schedule.print'))
            ->assertForbidden();
        $this->actingAs($other['faculty'])
            ->get(route('student.schedule.print'))
            ->assertForbidden();
    }

    #[Test]
    public function empty_schedule_print_outputs_are_clear_and_do_not_create_access_logs(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $student = $this->user('student');

        $this->actingAs($faculty)
            ->get(route('faculty.schedule.print'))
            ->assertOk()
            ->assertSee('No current published schedule is available for this account.');

        $this->actingAs($student)
            ->get(route('student.schedule.print'))
            ->assertOk()
            ->assertSee('No current published schedule is available for this account.');

        $this->assertDatabaseMissing('output_access_logs', [
            'actor_user_id' => $faculty->id,
            'output_type' => 'FACULTY_SCHEDULE',
            'action' => 'PRINT',
        ]);
        $this->assertDatabaseMissing('output_access_logs', [
            'actor_user_id' => $student->id,
            'output_type' => 'STUDENT_SCHEDULE',
            'action' => 'PRINT',
        ]);
    }

    private function createRun(User $registrar, array $diagnostics = []): ScheduleGenerationRun
    {
        return ScheduleGenerationRun::query()->create([
            'term_id' => Term::factory()->create()->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['contract_version' => 'tal94-demand-v2'],
            'input_hash' => hash('sha256', 'tal-96d3a-'.uniqid()),
            'solver_version' => 'tal-96d3a-test',
            'runtime_ms' => 8750,
            'objective_value' => 12.5,
            'diagnostics' => $diagnostics,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleFixture(): array
    {
        $student = $this->user('student');
        $faculty = $this->staff(User::StaffRoleFaculty);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->for($student)->for($program)->create();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateActive,
        ]);
        $profile->update(['curriculum_version_id' => $curriculum->id]);
        $course = Course::factory()->create();
        $specification = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $component = CourseComponent::factory()->for($specification)->create();
        $entry = CurriculumEntry::factory()
            ->for($curriculum)
            ->for($specification, 'courseSpecification')
            ->create();
        $offering = TermOffering::factory()
            ->for($term)
            ->for($entry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => TermOffering::StateScheduled,
                'expected_count' => 30,
            ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'officially_enrolled',
            'registered_at' => now(),
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create();
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['scheduling_demands' => [['scheduling_demand_id' => $demand->id]]],
            'input_hash' => hash('sha256', 'print-'.uniqid()),
            'solver_version' => 'tal-96d3a-test',
            'published_by' => $registrar->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $room = Room::factory()->create();
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
        StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'section_meeting_id' => $meeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);

        return compact(
            'student',
            'faculty',
            'profile',
            'term',
            'course',
            'enrollment',
        );
    }

    private function staff(string $role): User
    {
        return $this->user($role);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
