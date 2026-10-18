<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyQualifications\Pages\ListFacultyQualifications;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\ScheduleGenerationRunInfolist;
use App\Filament\Resources\Sections\Pages\ListSections;
use App\Filament\Resources\Sections\SectionResource;
use App\Jobs\ScheduleSolverDispatchJob;
use App\Models\AcademicYear;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\FacultyQualification;
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
use Database\Seeders\DatabaseSeeder;
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
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());
        $this->seed(DatabaseSeeder::class);

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
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $offering = $this->createTermOffering($term);
        $class = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id, 'confirmed_at' => now(),
        ]);
        $group = SectionDeliveryGroup::factory()->for($class, 'section')->create();
        SchedulingDemand::factory()->for($offering, 'termOffering')->for($group, 'sectionDeliveryGroup')->create();
        Room::factory()->create(['is_active' => true]);
        $mockRun = ScheduleGenerationRun::factory()->make([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusQueued,
            'requested_by' => $registrar->id,
        ]);

        $service = $this->mock(ScheduleGenerationService::class);
        $service->shouldReceive('generate')
            ->once()
            ->withArgs(fn (Term $t, User $u) => $t->id === $term->id && $u->id === $registrar->id)
            ->andReturnUsing(function () use ($mockRun): ScheduleGenerationRun {
                $mockRun->save();

                return $mockRun;
            });

        Livewire::actingAs($registrar)
            ->test(TermTimetable::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->assertActionVisible('generateTimetable')
            ->callAction('generateTimetable')
            ->assertHasNoActionErrors()
            ->assertNotified('Timetable generation requested');
    }

    public function test_term_context_is_preserved_in_source_record_actions_and_run_evidence_links(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicYear = $this->getOrCreateAcademicYear();
        $term = Term::factory()->for($academicYear, 'academicYear')->create();
        $offering = $this->createTermOffering($term);
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $qualification = FacultyQualification::factory()->create(['recorded_by' => $registrar->id]);
        $room = Room::factory()->create();

        Livewire::actingAs($registrar)
            ->test(ListSections::class, ['termId' => $term->id])
            ->assertTableActionHasUrl('view', SectionResource::getUrl('view', ['record' => $section, 'termId' => $term->id]), $section)
            ->assertTableActionHasUrl('edit', SectionResource::getUrl('edit', ['record' => $section, 'termId' => $term->id]), $section);

        Livewire::actingAs($registrar)
            ->test(ListFacultyQualifications::class, ['termId' => $term->id])
            ->assertTableActionHasUrl('view', FacultyQualificationResource::getUrl('view', ['record' => $qualification, 'termId' => $term->id]), $qualification)
            ->assertTableActionHasUrl('edit', FacultyQualificationResource::getUrl('edit', ['record' => $qualification, 'termId' => $term->id]), $qualification);

        Livewire::actingAs($registrar)
            ->test(ListRooms::class, ['termId' => $term->id])
            ->assertTableActionHasUrl('edit', RoomResource::getUrl('edit', ['record' => $room, 'termId' => $term->id]), $room);

        $source = ScheduleGenerationRunInfolist::sourcePresentation(['source_type' => 'room', 'source_id' => $room->id], $term->id);
        $this->assertSame(RoomResource::getUrl('view', ['record' => $room, 'termId' => $term->id]), $source['url']);

        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusBlocked,
            'diagnostics' => ['solver_result' => ['findings' => [[
                'severity' => 'blocking',
                'constraint' => 'room_capacity',
                'message' => 'The selected room cannot hold this class.',
                'source_type' => 'room',
                'source_id' => $room->id,
                'source_field' => 'capacity',
            ]]]],
        ]);

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $run->id, 'termId' => $term->id])
            ->assertSee(RoomResource::getUrl('view', ['record' => $room, 'termId' => $term->id]));
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
            ->test(TermTimetable::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->assertViewHas('activeRun', fn ($activeRun): bool => $activeRun->id === $run->id)
            ->assertSee('Under Review')->assertViewHas('solverOutcome', fn (array $outcome): bool => $outcome['status'] === 'FEASIBLE')
            ->assertSee('Hard validity not established')->assertDontSee('0 recorded clashes')->assertSee('Whole Term')->assertSee('Meeting list')
            ->assertSee('TEST-RM-101')
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

    public function test_technical_failed_solver_run_renders_diagnostics_and_allows_retry(): void
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
                    'latest_outcome' => 'technical_failure',
                    'failure' => [
                        'code' => 'TRANSPORT_TIMEOUT',
                        'message' => 'The solver connection timed out without a trusted result.',
                        'retryable' => true,
                        'final' => true,
                    ],
                ],
                'solver_outcome' => [
                    'status' => 'TECHNICAL_FAILURE',
                    'conflicts' => [
                        ['type' => 'room_capacity', 'description' => 'Room capacity exceeded by section demand.'],
                    ],
                ],
            ],
        ]);

        $diagnostics = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $run->id])
            ->assertSee('What happens next')
            ->assertSee('Classes & sections')
            ->assertSee('Faculty & rooms');
        $sourceUrls = array_map(fn ($item): string => $item->getUrl(), $diagnostics->instance()->getWorkspaceNavigation());
        $this->assertContains(TermClasses::getUrl(['termId' => $term->id]), $sourceUrls);
        $this->assertContains(TermResources::getUrl(['termId' => $term->id]), $sourceUrls);

        Livewire::actingAs($registrar)
            ->test(TermTimetable::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->assertViewHas('solverOutcome', fn (array $outcome): bool => $outcome['status'] === 'TECHNICAL_FAILURE')
            ->assertSee('The solver connection timed out without a trusted result.')
            ->assertViewHas('solverOutcome', fn (array $outcome): bool => data_get($outcome, 'conflicts.0.description') === 'Room capacity exceeded by section demand.')
            ->assertSee('Inspect generation diagnostics and validation findings')
            ->assertActionVisible('retrySolverRun')
            ->callAction('retrySolverRun')
            ->assertHasNoActionErrors()
            ->assertNotified('Solver run requeued');

        $this->assertSame(ScheduleGenerationRun::StatusQueued, $run->fresh()->status);
        Queue::assertPushed(ScheduleSolverDispatchJob::class);
    }

    public function test_publish_official_timetable_records_optional_reference_and_switches_to_published_tab(): void
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
            ->test(TermTimetable::class, [
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
            ->assertSee('Official v1')
            ->assertSee('v1')
            ->assertSee('REG-AUTH-2026-TEST-01')
            ->assertSee('Print official timetable');

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
            ->test(TermTimetable::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->assertSee('Read-only · Registrar owns these records')
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
            ->test(TermTimetable::class, [
                'termId' => $term->id,
                'timetableState' => 'official',
                'selectedVersionId' => 999999,
            ])
            ->assertSet('selectedVersionId', null)
            ->assertSee('Official v1')
            ->assertSee('REG-TEST-FALLBACK-01')
            ->assertSee('v1')
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
            'input_snapshot' => ['term' => ['scheduling_days' => [1, 2], 'scheduling_day_starts_at' => '08:00', 'scheduling_day_ends_at' => '17:00', 'teaching_grid_rows' => [
                ['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '17:00'],
                ['day_of_week' => 2, 'starts_at' => '08:00', 'ends_at' => '17:00'],
            ]]],
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
            ->test(TermTimetable::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ]);

        $presentation = $testable->viewData('candidatePresentation');
        $this->assertSame(['Monday', 'Tuesday'], array_column($presentation['days'], 'label'));
        $this->assertSame('8:00 AM', $presentation['ticks'][0]['label']);
        $this->assertSame('5:00 PM', $presentation['ticks'][array_key_last($presentation['ticks'])]['label']);

        $testable->call('showTimetableView', 'faculty')->set('viewFilter', $faculty->name)->assertSee('8:00 AM')
            ->assertSee('4:00 PM')
            ->assertDontSee('7:00 AM')
            ->assertDontSee('8:00 PM');
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
            'input_snapshot' => ['term' => [
                'scheduling_days' => [1],
                'teaching_grid_rows' => [['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '17:00']],
            ]],
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
            ->test(TermTimetable::class, [
                'termId' => $term->id,
                'viewTab' => 'generate',
            ])
            ->call('showTimetableView', 'faculty')->set('viewFilter', $faculty->name)
            ->assertSee('8:00 AM–10:00 AM')
            ->assertSee('2h')
            ->assertSee('RM-DUR-101')
            ->assertDontSee('Continuing until')
            ->assertSee('Teacher')->assertDontSee('Weekly overview')->assertSee('2h')
            ->assertViewHas('candidatePresentation', fn (array $presentation): bool => $presentation['meetings'][0]['duration'] === '2h');
    }

    public function test_switching_terms_clears_review_filters_and_keeps_the_new_context(): void
    {
        $first = Term::factory()->create();
        $second = Term::factory()->create();

        Livewire::actingAs($this->staff(User::StaffRoleRegistrar))
            ->test(TermTimetable::class, ['termId' => $first->id, 'viewTab' => 'generate'])
            ->set('candidateFilterFaculty', '123')
            ->set('candidateFilterRoom', '456')
            ->set('candidateFilterSection', 'old-section')
            ->set('candidateFilterModality', 'old-modality')
            ->call('selectTerm', $second->id)
            ->assertSet('termId', $second->id)
            ->assertSet('viewTab', 'generate')
            ->assertSet('selectedRunId', null)
            ->assertSet('selectedVersionId', null)
            ->assertSet('candidateFilterFaculty', null)
            ->assertSet('candidateFilterRoom', null)
            ->assertSet('candidateFilterSection', null)
            ->assertSet('candidateFilterModality', null);
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
