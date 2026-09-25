<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\AcademicYear;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RegistrarTermPlanningSchedulingWorkbenchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        config()->set('tala_integrations.scheduling_solver.driver', 'local_stub');
    }

    public function test_exact_term_workbench_generates_timetable_without_term_prompt(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicYear = $this->getOrCreateAcademicYear();
        $term = Term::factory()->for($academicYear, 'academicYear')->create();
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $mockRun = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusQueued,
            'requested_by' => $registrar->id,
        ]);

        $service = $this->mock(ScheduleGenerationService::class);
        $service->shouldReceive('generate')
            ->once()
            ->withArgs(fn (Term $t, User $u) => $t->id === $term->id && $u->id === $registrar->id)
            ->andReturn($mockRun);

        Livewire::actingAs($registrar)
            ->test(TermPlanningWorkbench::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->assertActionVisible('generateTimetable')
            ->callAction('generateTimetable')
            ->assertHasNoActionErrors()
            ->assertNotified('Timetable generation requested');
    }

    public function test_candidate_review_renders_matrix_quality_measures_and_review_actions(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicYear = $this->getOrCreateAcademicYear();
        $term = Term::factory()->for($academicYear, 'academicYear')->create();
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => null,
            'candidate_version' => 1,
            'runtime_ms' => 1450,
            'objective_value' => 12.50,
            'quality_measures' => [
                'hard_violations' => 0,
                'soft_penalty' => 12.50,
                'room_seat_waste' => 0,
                'faculty_idle_time' => 3,
                'cohort_idle_time' => 2,
            ],
            'diagnostics' => [
                'solver_outcome' => [
                    'status' => 'FEASIBLE',
                    'solve_time' => 1.45,
                ],
            ],
        ]);

        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create(['code' => 'TEST-RM-101']);
        $offering = $this->createTermOffering($term);
        $demand = SchedulingDemand::factory()->for($offering)->create([
            'modality' => TermOffering::ModalityFaceToFace,
        ]);

        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        Livewire::actingAs($registrar)
            ->test(TermPlanningWorkbench::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->assertSee("Run #{$run->id}")
            ->assertSee('Under Review')
            ->assertSee('CP-SAT: FEASIBLE')
            ->assertSee('0 Collisions')
            ->assertSee('Weekly Time-Block Matrix')
            ->assertSee('TEST-RM-101')
            ->call('toggleControlDeck')
            ->assertSet('isControlDeckCollapsed', true)
            ->assertSee('Expand Deck')
            ->call('toggleControlDeck')
            ->assertSet('isControlDeckCollapsed', false)
            ->call('setCandidateViewMode', 'table')
            ->assertSet('candidateViewMode', 'table')
            ->assertSee('Candidate Schedule Meeting Records')
            ->assertSee('TEST-RM-101')
            ->call('setCandidateViewMode', 'matrix')
            ->assertSet('candidateViewMode', 'matrix')
            ->assertActionVisible('acceptCandidate')
            ->assertActionVisible('rejectCandidate')
            ->callAction('acceptCandidate', [
                'candidate_review_reason' => 'Curricular hours and room capacities validated.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Candidate Accepted');

        $this->assertSame('Accepted', $run->fresh()->candidate_state);
        $this->assertSame($registrar->id, $run->fresh()->candidate_reviewed_by);
    }

    public function test_infeasible_failed_solver_run_renders_diagnostics_and_allows_retry(): void
    {
        Queue::fake([ScheduleSolverDispatchJob::class]);

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicYear = $this->getOrCreateAcademicYear();
        $term = Term::factory()->for($academicYear, 'academicYear')->create();
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusFailed,
            'candidate_key' => null,
            'published_at' => null,
            'diagnostics' => [
                'solver_dispatch' => [
                    'status' => 'failed',
                    'dispatch_cycle' => 1,
                    'latest_outcome' => 'infeasible',
                    'failure' => [
                        'code' => 'INFEASIBLE',
                        'message' => 'The CP-SAT solver proved no schedule exists satisfying all hard constraints.',
                        'retryable' => true,
                        'final' => true,
                    ],
                ],
                'solver_outcome' => [
                    'status' => 'INFEASIBLE',
                    'conflicts' => [
                        ['type' => 'room_capacity', 'description' => 'Room capacity exceeded by section demand.'],
                    ],
                ],
            ],
        ]);

        Livewire::actingAs($registrar)
            ->test(TermPlanningWorkbench::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->assertSee('Solver Outcome: INFEASIBLE')
            ->assertSee('The CP-SAT solver proved no schedule exists satisfying all hard constraints.')
            ->assertSee('Room capacity exceeded by section demand.')
            ->assertSee('Actionable Recovery Steps')
            ->assertActionVisible('retrySolverRun')
            ->callAction('retrySolverRun')
            ->assertHasNoActionErrors()
            ->assertNotified('Solver run requeued');

        $this->assertSame(ScheduleGenerationRun::StatusQueued, $run->fresh()->status);
        Queue::assertPushed(ScheduleSolverDispatchJob::class);
    }

    public function test_publish_official_timetable_requires_authority_reference_and_switches_to_published_tab(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicYear = $this->getOrCreateAcademicYear();
        $term = Term::factory()->for($academicYear, 'academicYear')->create();
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'Accepted',
            'candidate_reviewed_by' => $registrar->id,
            'candidate_reviewed_at' => now(),
            'candidate_version' => 1,
        ]);

        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create();
        $offering = $this->createTermOffering($term);
        $section = Section::factory()->for($package, 'calendarPackage')->create([
            'term_calendar_package_id' => $package->id,
            'confirmed_at' => now(),
        ]);
        $deliveryGroup = SectionDeliveryGroup::factory()->for($section)->create();
        $course = Course::factory()->create();
        $spec = CourseSpecification::factory()->for($course)->create();
        $component = CourseComponent::factory()->for($spec)->create();

        $demand = SchedulingDemand::factory()->for($offering)->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'course_component_id' => $component->id,
            'section_delivery_group_id' => $deliveryGroup->id,
        ]);

        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        $service = $this->mock(SchedulePublishService::class);
        $service->shouldReceive('publicationReasonRequirement')->andReturn(null);
        $service->shouldReceive('publish')
            ->once()
            ->withArgs(function ($r, $actor, $note, $authorityReference) use ($run, $registrar) {
                return $r->id === $run->id
                    && $actor->id === $registrar->id
                    && $authorityReference === 'REG-AUTH-2026-TEST-01';
            })
            ->andReturnUsing(function () use ($run, $term) {
                PublishedTimetableVersion::factory()->create([
                    'term_id' => $term->id,
                    'schedule_run_id' => $run->id,
                    'version' => 1,
                    'state' => PublishedTimetableVersion::StatePublished,
                    'authority_reference' => 'REG-AUTH-2026-TEST-01',
                    'published_at' => now(),
                ]);
                $run->update(['status' => ScheduleGenerationRun::StatusPublished]);

                return $run;
            });

        Livewire::actingAs($registrar)
            ->test(TermPlanningWorkbench::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->assertActionVisible('publishOfficialTimetable')
            ->callAction('publishOfficialTimetable', [
                'authority_reference' => 'REG-AUTH-2026-TEST-01',
                'publication_note' => 'Approved by Registrar sign-off.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Official Timetable Published')
            ->assertSet('viewTab', 'published')
            ->assertSee('Official Published Timetable')
            ->assertSee('Official Version 1')
            ->assertSee('REG-AUTH-2026-TEST-01')
            ->assertSee('Print Official A4 Timetable (Landscape)');

        $this->assertDatabaseHas('published_timetable_versions', [
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'authority_reference' => 'REG-AUTH-2026-TEST-01',
            'state' => PublishedTimetableVersion::StatePublished,
            'version' => 1,
        ]);
    }

    public function test_academic_head_has_read_only_oversight_on_workbench_scheduling_tabs(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $academicYear = $this->getOrCreateAcademicYear();
        $term = Term::factory()->for($academicYear, 'academicYear')->create();
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
        ]);

        Livewire::actingAs($academicHead)
            ->test(TermPlanningWorkbench::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->assertSee('Read-only oversight')
            ->assertActionHidden('generateTimetable')
            ->assertActionHidden('acceptCandidate')
            ->assertActionHidden('rejectCandidate')
            ->assertActionHidden('retrySolverRun')
            ->assertActionHidden('publishOfficialTimetable');
    }

    public function test_invalid_selected_version_id_falls_back_to_current_published_version(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicYear = $this->getOrCreateAcademicYear();
        $term = Term::factory()->for($academicYear, 'academicYear')->create();
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
        ]);

        PublishedTimetableVersion::factory()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'REG-TEST-FALLBACK-01',
            'published_at' => now(),
        ]);

        Livewire::actingAs($registrar)
            ->test(TermPlanningWorkbench::class, [
                'termId' => $term->id,
                'viewTab' => 'published',
                'selectedVersionId' => 999999,
            ])
            ->assertSet('selectedVersionId', null)
            ->assertSee('Official Published Timetable')
            ->assertSee('REG-TEST-FALLBACK-01')
            ->assertSee('Official Version 1')
            ->call('selectPublishedVersion', 888888)
            ->assertSet('selectedVersionId', null)
            ->assertSee('REG-TEST-FALLBACK-01');
    }

    public function test_derived_matrix_hours_adapt_to_custom_teaching_grid_bounds(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicYear = $this->getOrCreateAcademicYear();
        $term = Term::factory()->for($academicYear, 'academicYear')->create([
            'scheduling_day_starts_at' => '07:00:00',
            'scheduling_day_ends_at' => '21:00:00',
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        TermTeachingGridRow::query()->create([
            'term_calendar_package_id' => $package->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);

        TermTeachingGridRow::query()->create([
            'term_calendar_package_id' => $package->id,
            'day_of_week' => 2,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);

        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
        ]);

        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create();
        $offering = $this->createTermOffering($term);
        $demand = SchedulingDemand::factory()->for($offering)->create([
            'modality' => TermOffering::ModalityFaceToFace,
        ]);

        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        $testable = Livewire::actingAs($registrar)
            ->test(TermPlanningWorkbench::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ]);

        $matrixHours = $testable->viewData('matrixHours');
        $this->assertSame(range(8, 16), $matrixHours);

        $testable->assertSee('08:00')
            ->assertSee('16:00')
            ->assertDontSee('07:00')
            ->assertDontSee('20:00');
    }

    public function test_weekly_matrix_renders_truthful_meeting_duration_and_spanning_hours(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicYear = $this->getOrCreateAcademicYear();
        $term = Term::factory()->for($academicYear, 'academicYear')->create();
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => null,
            'candidate_version' => 1,
            'runtime_ms' => 1200,
        ]);

        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create(['code' => 'RM-DUR-101']);
        $offering = $this->createTermOffering($term);
        $demand = SchedulingDemand::factory()->for($offering)->create([
            'modality' => TermOffering::ModalityFaceToFace,
        ]);

        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        Livewire::actingAs($registrar)
            ->test(TermPlanningWorkbench::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->assertSee('08:00–10:00')
            ->assertSee('2h')
            ->assertSee('RM-DUR-101')
            ->assertSee('Continuing until 10:00')
            ->assertSee('cont. (2h)')
            ->call('setCandidateViewMode', 'table')
            ->assertSee('Duration')
            ->assertSee('2h');
    }

    private function createTermOffering(Term $term): TermOffering
    {
        $entry = CurriculumEntry::query()->first();
        if (! $entry) {
            $entry = CurriculumEntry::factory()->create();
        }

        return TermOffering::factory()->for($term)->create(['curriculum_entry_id' => $entry->id]);
    }

    private function getOrCreateAcademicYear(): AcademicYear
    {
        return AcademicYear::query()->firstOrCreate(
            ['label' => '2026-2027'],
            [
                'starts_on' => '2026-08-01',
                'ends_on' => '2027-05-31',
                'state' => 'ACTIVE',
            ],
        );
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
