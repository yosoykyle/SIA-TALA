<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverRequest;
use App\Actions\Scheduling\AdjustCandidateMeeting;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\RecordTermCohort;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermResources;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93TimetablingPerformanceAndClassEditTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_generate_scheduling_demand_eliminates_n_plus_one_queries_at_scale(): void
    {
        $registrar = $this->registrar();
        [$term, $package, $program, $curriculum] = $this->activeTermContext();

        Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 50]);
        Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeComputerLaboratory, 'capacity' => 50]);

        $faculty = $this->faculty();
        FacultyTermLoadOverride::factory()->create([
            'faculty_user_id' => $faculty->id,
            'term_id' => $term->id,
            'default_max_units_snapshot' => 24,
            'approved_overload_units' => 6,
            'is_active' => true,
        ]);

        // 30 classes × 2 components (Lecture + Lab) = 60 scheduling demands.
        for ($i = 1; $i <= 30; $i++) {
            $course = Course::factory()->create(['code' => sprintf('PERF %03d', $i)]);
            $spec = CourseSpecification::factory()->for($course)->create([
                'state' => CourseSpecification::StateActive,
                'allowed_modalities' => [TermOffering::ModalityFaceToFace],
            ]);
            CourseComponent::factory()->for($spec)->create([
                'component_type' => CourseComponent::TypeLecture,
                'weekly_contact_hours' => 1.5,
                'meeting_pattern' => '1x90',
                'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                'sequence' => 1,
            ]);
            CourseComponent::factory()->for($spec)->create([
                'component_type' => CourseComponent::TypeLaboratory,
                'weekly_contact_hours' => 1.5,
                'meeting_pattern' => '1x90',
                'room_type_default' => CourseComponent::RoomTypeComputerLaboratory,
                'sequence' => 2,
            ]);
            FacultyQualification::factory()->create([
                'faculty_user_id' => $faculty->id,
                'course_id' => $course->id,
                'is_active' => true,
            ]);

            $entry = CurriculumEntry::factory()
                ->for($curriculum, 'curriculumVersion')
                ->for($spec, 'courseSpecification')
                ->create(['term_type' => $term->type, 'year_level' => '1']);

            $offering = TermOffering::factory()
                ->for($term)
                ->for($entry, 'curriculumEntry')
                ->create([
                    'modality' => TermOffering::ModalityFaceToFace,
                    'expected_count' => 25,
                    'state' => TermOffering::StatePendingScheduling,
                ]);

            $section = Section::query()->create([
                'term_offering_id' => $offering->id,
                'term_calendar_package_id' => $package->id,
                'course_specification_id' => $spec->id,
                'code' => sprintf('PERF%03d-A', $i),
                'class_reference' => sprintf('PERF%03d-A', $i),
                'capacity' => 30,
                'state' => Section::StatePlanned,
                'confirmed_at' => now(),
                'confirmed_by' => $registrar->id,
            ]);

            SectionDeliveryGroup::query()->create([
                'section_id' => $section->id,
                'name' => sprintf('PERF%03d-A', $i),
                'expected_count' => 25,
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionDeliveryGroup::StateReady,
            ]);
        }

        // Warm auth permission cache as occurs during authenticated requests.
        Gate::forUser($registrar)->allows('create', SchedulingDemand::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $firstQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(60, $first['created']);
        $this->assertSame(60, $first['ready']);
        $this->assertSame(0, $first['action_required']);
        $this->assertLessThan(15, count($firstQueries), 'GenerateSchedulingDemand::forTerm() should execute < 15 queries even for 60 demands, got '.count($firstQueries));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $second = app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $secondQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(60, $second['skipped']);
        $this->assertLessThan(15, count($secondQueries), 'Unchanged re-sync should execute < 15 queries, got '.count($secondQueries));
    }

    public function test_snapshot_capture_bulk_ingestion_and_draft_adjustment_eliminate_n_plus_one_and_default_draft_reason(): void
    {
        $registrar = $this->registrar();
        [$term, $package, $program, $curriculum] = $this->activeTermContext();
        $package->teachingGridRows()->update(['breaks' => []]);

        $lectureRoom = Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 40]);
        $faculty = $this->faculty();
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );
        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSIT 1-A',
            'confirmed_count' => 30,
        ]);

        // 10 distinct courses = 10 demands.
        $demandsCreated = 0;
        for ($i = 1; $i <= 10; $i++) {
            $course = Course::factory()->create(['code' => sprintf('SNAP %02d', $i)]);
            $spec = CourseSpecification::factory()->for($course)->create([
                'state' => CourseSpecification::StateActive,
                'credit_units' => 1.0,
                'allowed_modalities' => [TermOffering::ModalityFaceToFace],
            ]);
            CourseComponent::factory()->for($spec)->create([
                'component_type' => CourseComponent::TypeLecture,
                'weekly_contact_hours' => 1.0,
                'meeting_pattern' => '1x60',
                'room_type_default' => CourseComponent::RoomTypeLectureRoom,
            ]);
            FacultyQualification::factory()->create([
                'faculty_user_id' => $faculty->id,
                'course_id' => $course->id,
                'is_active' => true,
            ]);
            $entry = CurriculumEntry::factory()
                ->for($curriculum, 'curriculumVersion')
                ->for($spec, 'courseSpecification')
                ->create(['term_type' => $term->type, 'year_level' => '1']);
            $offering = TermOffering::factory()
                ->for($term)
                ->for($entry, 'curriculumEntry')
                ->create([
                    'modality' => TermOffering::ModalityFaceToFace,
                    'expected_count' => 25,
                    'state' => TermOffering::StatePendingScheduling,
                ]);
            $section = Section::factory()
                ->for($offering, 'termOffering')
                ->create([
                    'code' => sprintf('SNAP%02d-BSIT1A', $i),
                    'capacity' => 30,
                    'state' => Section::StatePlanned,
                ]);
            SectionDeliveryGroup::factory()
                ->for($section)
                ->create([
                    'name' => sprintf('SNAP%02d-BSIT1A', $i),
                    'expected_count' => 25,
                    'modality' => TermOffering::ModalityFaceToFace,
                    'state' => SectionDeliveryGroup::StateReady,
                ]);
            $section = app(PrepareClassOffering::class)->execute($term, $section, $registrar, [
                'source' => Section::SourceRegular,
                'delivery_mode' => TermOffering::ModalityFaceToFace,
            ]);
            app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 25]);
            $demandsCreated++;
        }

        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusQueued,
            'input_snapshot' => ['capture' => 'pending'],
            'input_hash' => hash('sha256', 'perf-snapshot-pending'),
            'solver_version' => 'pending-dispatch',
            'candidate_state' => 'Queued',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($run);
        $captureQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount($demandsCreated, $snapshot['scheduling_demands']);
        $qualQueries = collect($captureQueries)->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'from `faculty_qualifications`'))->count();
        $overrideQueries = collect($captureQueries)->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'from `faculty_term_load_overrides`'))->count();
        $declQueries = collect($captureQueries)->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'from `faculty_availability_declarations`'))->count();

        $this->assertLessThanOrEqual(3, $qualQueries, 'Faculty qualifications should not be queried per demand (got '.$qualQueries.' for '.$demandsCreated.' demands).');
        $this->assertLessThanOrEqual(2, $overrideQueries, 'Faculty load overrides should not be queried per demand (got '.$overrideQueries.' for '.$demandsCreated.' demands).');
        $this->assertLessThanOrEqual(3, $declQueries, 'Faculty declarations should not be queried per demand (got '.$declQueries.' for '.$demandsCreated.' demands).');

        config()->set('tala_integrations.scheduling_solver.driver', 'local_stub');
        $solverResult = app(LocalStubSchedulingSolverClient::class)
            ->solve(new SchedulingSolverRequest($snapshot, 'perf-run-'.$run->id))
            ->payload();
        $this->assertSame('feasible', $solverResult['solver_status'], json_encode($solverResult));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $summary = app(ScheduleCloudResultIngestor::class)->ingest($run, $solverResult);
        $ingestQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('accepted', $summary['status'], json_encode($summary));
        $insertCandidateQueries = collect($ingestQueries)
            ->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'insert into `candidate_schedule_rows`'))
            ->count();
        $this->assertSame(1, $insertCandidateQueries, 'Ingestor should bulk-insert all CandidateScheduleRows in 1 query.');
        $this->assertSame(10, $run->fresh()->candidateRows()->count());

        // Adjust the first candidate row WITHOUT passing explicit reason/authority (draft default).
        $firstRow = $run->fresh()->candidateRows()->orderBy('id')->firstOrFail();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $successor = app(AdjustCandidateMeeting::class)->execute(
            requestedRow: $firstRow,
            actor: $registrar,
            assignment: [
                'day_of_week' => 5,
                'starts_at' => '15:00:00',
                'ends_at' => '16:00:00',
            ],
        );
        $adjustQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $adjustInsertQueries = collect($adjustQueries)
            ->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'insert into `candidate_schedule_rows`'))
            ->count();
        $this->assertSame(1, $adjustInsertQueries, 'AdjustCandidateMeeting should bulk-insert successor CandidateScheduleRows in 1 query.');
        $this->assertSame(10, $successor->candidateRows->count());

        $adjustedRow = $successor->candidateRows->firstWhere('change_type', 'LocalAdjustment');
        $this->assertInstanceOf(CandidateScheduleRow::class, $adjustedRow);
        $this->assertSame(5, $adjustedRow->day_of_week);
        $this->assertSame('15:00:00', $adjustedRow->starts_at);
        $this->assertSame('Registrar draft adjustment', $adjustedRow->override_reason);
        $this->assertSame('Registrar draft adjustment', $adjustedRow->override_authority);
    }

    public function test_term_classes_edit_action_supports_confirmed_unpublished_classes_lecture_lab_room_overrides_and_curriculum_sync(): void
    {
        $registrar = $this->registrar();
        [$term, $package, $program, $oldCurriculum] = $this->activeTermContext();

        Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 50]);
        Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeComputerLaboratory, 'capacity' => 50]);
        $faculty = $this->faculty();

        $course = Course::factory()->create(['code' => 'IT 201']);
        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $course->id, 'is_active' => true]);

        // Initial active spec with MISSING room_type_default on both Lecture & Lab.
        $oldSpec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Database Systems',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline],
        ]);
        CourseComponent::factory()->for($oldSpec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1.5,
            'meeting_pattern' => '1x90',
            'room_type_default' => null,
            'sequence' => 1,
        ]);
        CourseComponent::factory()->for($oldSpec)->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => 1.5,
            'meeting_pattern' => '1x90',
            'room_type_default' => null,
            'sequence' => 2,
        ]);

        $oldEntry = CurriculumEntry::factory()
            ->for($oldCurriculum, 'curriculumVersion')
            ->for($oldSpec, 'courseSpecification')
            ->create(['term_type' => $term->type, 'year_level' => '2']);
        $offering = TermOffering::factory()
            ->for($term)
            ->for($oldEntry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'expected_count' => 25,
                'state' => TermOffering::StatePendingScheduling,
            ]);
        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $oldCurriculum->id,
            'reference' => 'BSIT 2-A',
            'confirmed_count' => 35,
        ]);

        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'IT201-BSIT2A',
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ]);
        SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'IT201-BSIT2A',
            'expected_count' => 25,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section = app(PrepareClassOffering::class)->execute($term, $section, $registrar, [
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        $section = app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 25]);

        // Now simulate editing the subject & curriculum: old ones become retired/superseded, new active ones are created.
        $oldSpec->update(['state' => CourseSpecification::StateRetired]);
        $oldCurriculum->update(['state' => CurriculumVersion::StateSuperseded]);

        $newSpec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Database Systems (Updated)',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline],
        ]);
        $newLecture = CourseComponent::factory()->for($newSpec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1.5,
            'meeting_pattern' => '1x90',
            'room_type_default' => null,
            'sequence' => 1,
        ]);
        $newLab = CourseComponent::factory()->for($newSpec)->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => 1.5,
            'meeting_pattern' => '1x90',
            'room_type_default' => null,
            'sequence' => 2,
        ]);
        $newCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Curriculum 2027',
            'state' => CurriculumVersion::StateActive,
        ]);
        $newEntry = CurriculumEntry::factory()
            ->for($newCurriculum, 'curriculumVersion')
            ->for($newSpec, 'courseSpecification')
            ->create(['term_type' => $term->type, 'year_level' => '2']);

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);

        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class);
        // Surfaces Load from curriculum + inline findings + shows Edit on confirmed unpublished class.
        $page->assertSee('Load from curriculum')
            ->assertSee('Room type needed')
            ->assertSee('Face-to-Face demand needs a room type')
            ->assertTableActionVisible('editClass', $section);

        // Validation error sends danger toast and makes no write when learners exceed capacity.
        $page->mountTableAction('editClass', $section)
            ->setTableActionData([
                'delivery_mode' => TermOffering::ModalityFaceToFace,
                'lecture_room_type' => CourseComponent::RoomTypeLectureRoom,
                'laboratory_room_type' => CourseComponent::RoomTypeComputerLaboratory,
                'sync_latest_curriculum' => true,
                'cohorts' => [['term_cohort_id' => $cohort->id, 'expected_count' => 30]],
            ])
            ->setTableActionData(['capacity' => 20])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['capacity'])
            ->assertNotified('Cannot save class changes')
            ->call('unmountAction');
        $this->assertSame(30, $section->fresh()->capacity);

        // Valid Edit updates capacity, cohort count, per-component Lecture & Lab room overrides, and syncs active subject revision without overwriting cohort curriculum batch!
        $page->mountTableAction('editClass', $section)
            ->setTableActionData([
                'delivery_mode' => TermOffering::ModalityFaceToFace,
                'lecture_room_type' => CourseComponent::RoomTypeLectureRoom,
                'laboratory_room_type' => CourseComponent::RoomTypeComputerLaboratory,
                'sync_latest_curriculum' => true,
                'cohorts' => [['term_cohort_id' => $cohort->id, 'expected_count' => 32]],
            ])
            ->setTableActionData(['capacity' => 40])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Class and room requirements updated');

        $section->refresh();
        $offering->refresh();

        $this->assertSame(40, $section->capacity);
        $this->assertSame(32, (int) $section->cohorts()->firstOrFail()->pivot->expected_count);
        $this->assertSame($oldCurriculum->id, $cohort->fresh()->curriculum_version_id);
        $this->assertSame($newSpec->id, $section->course_specification_id);

        $demands = SchedulingDemand::query()->where('term_offering_id', $offering->id)->get()->keyBy('course_component_id');
        $this->assertCount(2, $demands);
        $this->assertSame(CourseComponent::RoomTypeLectureRoom, data_get($demands->get($newLecture->id)?->source_snapshot, 'room_type_requirement'));
        $this->assertSame(CourseComponent::RoomTypeComputerLaboratory, data_get($demands->get($newLab->id)?->source_snapshot, 'room_type_requirement'));
        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $demands->get($newLecture->id)?->validation_state);
        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $demands->get($newLab->id)?->validation_state);

        // Refreshed table shows human-readable room requirements inline.
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertSee('Lecture room + Computer lab')
            ->assertDontSee('Face-to-Face demand needs a room type');

        // Once published, Edit action is hidden.
        PublishedTimetableMeeting::factory()->create(['section_id' => $section->id]);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertTableActionHidden('editClass', $section);
    }

    public function test_scalar_room_type_override_remains_backwards_compatible(): void
    {
        $registrar = $this->registrar();
        [$term, $package, , $curriculum] = $this->activeTermContext();

        Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLaboratory, 'capacity' => 40]);
        $faculty = $this->faculty();
        $course = Course::factory()->create(['code' => 'SCI 101']);
        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $course->id, 'is_active' => true]);

        $spec = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1.5,
            'meeting_pattern' => '1x90',
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create(['term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'room_type_override' => CourseComponent::RoomTypeLaboratory,
            'expected_count' => 20,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $spec->id,
            'capacity' => 30,
            'confirmed_at' => now(),
            'confirmed_by' => $registrar->id,
        ]);
        SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 20,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);

        $summary = app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $this->assertSame(1, $summary['ready']);
        $demand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->sole();
        $this->assertSame(CourseComponent::RoomTypeLaboratory, data_get($demand->source_snapshot, 'room_type_requirement'));
    }

    public function test_auto_generated_class_references_hide_manual_inputs_preserve_incomplete_proposal_feedback_and_hide_ulid_version_codes(): void
    {
        $registrar = $this->registrar();
        [$term, , $program] = $this->activeTermContext();

        $ulidCode = 'BSIT-01J99999999999999999999999';
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Curriculum 2026',
            'version_code' => $ulidCode,
            'state' => CurriculumVersion::StateActive,
        ]);
        $course = Course::factory()->create(['code' => 'IT 101']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Introduction to Computing',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityOnline],
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1.5,
            'meeting_pattern' => '1x90',
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => $term->type,
        ]);
        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSIT 1-A',
            'confirmed_count' => 40,
        ]);

        // 1. TermClasses cohorts view & recordCohort modal show curriculum name, never raw ULID version_code.
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])->test(TermClasses::class)
            ->assertSee('BSIT Curriculum 2026')
            ->assertDontSee($ulidCode)
            ->mountAction('recordCohort')
            ->assertMountedActionModalSee('BSIT Curriculum 2026')
            ->assertMountedActionModalDontSee($ulidCode);

        // 2. TermClasses loadCurriculumSubjects modal hides manual Class reference input and raw ULID version_code.
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->mountAction('loadCurriculumSubjects')
            ->setActionData(['term_cohort_id' => $cohort->id])
            ->assertMountedActionModalSee('BSIT Curriculum 2026')
            ->assertMountedActionModalDontSee($ulidCode)
            ->assertMountedActionModalDontSee('Class reference');

        // 3. Backend auto-generates deterministic unique class references (IT101-BSIT1A and IT101-BSIT1A-2 on collision) when code is omitted.
        $proposalService = app(PrepareTermClassProposal::class);
        $preview = $proposalService->preview($term);
        $result = $proposalService->confirm($term, $registrar, [
            [
                'action' => 'confirm',
                'entry_id' => $entry->id,
                'capacity' => 25,
                'source' => Section::SourceRegular,
                'modality' => TermOffering::ModalityOnline,
                'cohorts' => [['id' => $cohort->id, 'count' => 20]],
            ],
            [
                'action' => 'confirm',
                'entry_id' => $entry->id,
                'capacity' => 25,
                'source' => Section::SourceRegular,
                'modality' => TermOffering::ModalityOnline,
                'cohorts' => [['id' => $cohort->id, 'count' => 20]],
            ],
        ], $preview['token']);

        $this->assertSame(['confirmed' => 2, 'cancelled' => 0], $result);
        $codes = Section::query()
            ->whereHas('termOffering', fn ($q) => $q->where('term_id', $term->id))
            ->orderBy('id')
            ->pluck('class_reference')
            ->all();
        $this->assertSame(['IT101-BSIT1A', 'IT101-BSIT1A-2'], $codes);

        // 4. Prepare class modal in TermClasses has no manual 'Stable class reference' input.
        $draftOffering = TermOffering::query()->where('term_id', $term->id)->firstOrFail();
        $draftSection = Section::factory()->for($draftOffering, 'termOffering')->create([
            'code' => '',
            'class_reference' => null,
            'term_calendar_package_id' => null,
            'confirmed_at' => null,
            'capacity' => 30,
        ]);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->mountTableAction('prepareClassOffering', $draftSection)
            ->assertMountedActionModalDontSee('Stable class reference')
            ->setActionData([
                'section_id' => $draftSection->id,
                'source' => Section::SourceRegular,
                'delivery_mode' => TermOffering::ModalityOnline,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertNotEmpty($draftSection->fresh()->class_reference);
    }

    public function test_term_planning_step_pages_remove_calendar_disclosures_fix_duplicate_confirm_badge_and_use_compact_view_tabs(): void
    {
        $registrar = $this->registrar();
        [$term, $package, , $curriculum] = $this->activeTermContext();
        $package->update([
            'authority_reference' => 'MEMO-2026-09',
            'authority_date' => '2026-06-15',
        ]);

        // 1. TermCalendar: removes both supporting-details dropdowns, shows visual weekly hours + 3-fact summary + calm activity footnote (and omits routine approval note).
        $calendarPage = Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->assertSee('Monday')
            ->assertSee('8:00 AM–12:00 PM')
            ->assertSee('12:00 PM–1:00 PM')
            ->assertSee('1:00 PM–5:00 PM')
            ->assertSee('30-minute slots')
            ->assertDontSee('Approval note: MEMO-2026-09')
            ->assertSee('Times use Asia/Manila. Activity dates do not block class planning.')
            ->assertDontSee('Teaching hours and source details')
            ->assertDontSee('What these dates control');

        $this->assertStringNotContainsString('tala-supporting-details--inset', $calendarPage->html());

        // 2. TermClasses: unconfirmed class shows "Needs confirmation" badge (not duplicate "Confirm students" badge), keeps Confirm students button,
        // keeps Load from curriculum button, wraps cohort code with whitespace-nowrap, and uses compact tala-navigation tabs.
        $course = Course::factory()->create(['code' => 'IT 105']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Systems Fundamentals',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityOnline],
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1.5,
            'meeting_pattern' => '1x90',
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => $term->type,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityOnline,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSIT-2026-COHORT 1',
            'confirmed_count' => 28,
        ]);
        $unconfirmedSection = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'IT105-A',
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $spec->id,
            'capacity' => 30,
            'state' => Section::StatePlanned,
            'confirmed_at' => null,
        ]);
        $unconfirmedSection->cohorts()->attach($cohort->id, ['expected_count' => 28]);

        $classesPage = Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertSee('Load from curriculum')
            ->assertSee('Needs confirmation')
            ->assertSee('Confirm students')
            ->assertSeeHtml('<span class="inline-block whitespace-nowrap">BSIT-2026-COHORT 1</span>')
            ->assertSee('28 students')
            ->assertSeeHtml('tala-navigation tala-navigation-compact')
            ->assertDontSee('Review the classes that will run and the meetings they need.');

        // Exactly one occurrence of "Confirm students" (the row action button, never duplicated in the Status badge).
        $this->assertSame(1, substr_count($classesPage->html(), 'Confirm students'));

        // 3. TermResources: uses compact tala-navigation tabs, keeps key actions, and removes static inline card styling.
        $resourcesPage = Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertSeeHtml('tala-navigation tala-navigation-compact')
            ->assertSee('Assign subjects')
            ->assertSee('Manage in School Resources')
            ->assertSee('24 units/teacher')
            ->assertDontSeeHtml('style="padding: var(--tala-card-pad);"');

        $roomsHtml = $resourcesPage->call('chooseResourceView', 'rooms')->html(true);
        $this->assertSame(1, substr_count($roomsHtml, 'Manage room catalog'));
    }

    private function registrar(): User
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->syncPermissions([
            Permission::findOrCreate('manage-schedules', 'web'),
            Permission::findOrCreate('approve-documents', 'web'),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);

        return $user;
    }

    private function faculty(): User
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleFaculty);

        return $user;
    }

    /** @return array{Term, TermCalendarPackage, Program, CurriculumVersion} */
    private function activeTermContext(): array
    {
        $term = Term::factory()->create([
            'state' => Term::StateActive,
            'default_max_units' => 24,
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        foreach ([1, 2, 3, 4, 5] as $day) {
            TermTeachingGridRow::factory()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
            ]);
        }

        $program = Program::factory()->create(['name' => 'Bachelor of Science in Information Technology']);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Curriculum 2025',
            'state' => CurriculumVersion::StateActive,
        ]);

        return [$term, $package, $program, $curriculum];
    }
}
