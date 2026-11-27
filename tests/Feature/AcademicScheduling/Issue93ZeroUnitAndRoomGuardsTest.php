<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\FacultyAvailabilityStatusProjection;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\RecordTermCohort;
use App\Actions\Scheduling\ScheduleAssignmentValidationService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\TermClasses;
use App\Filament\Resources\Rooms\Pages\EditRoom;
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
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingCommitment;
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
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93ZeroUnitAndRoomGuardsTest extends TestCase
{
    use DatabaseTransactions;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function zero_unit_registrar_override_blocks_readiness_surfaces_faculty_status_and_excludes_from_zero_unit_course_snapshot(): void
    {
        $registrar = $this->registrar();
        $facultyA = $this->faculty();
        $facultyB = $this->faculty();

        [$term, $package, , $curriculum] = $this->activeTermContext();
        Room::factory()->create([
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);

        // 0-unit Non-Credit / NSTP-style course where only Faculty A is initially qualified.
        $nonCreditCourse = Course::factory()->create(['code' => 'NSTP 001']);
        $nonCreditSpec = CourseSpecification::factory()->for($nonCreditCourse)->create([
            'title' => 'Civic Welfare Training',
            'state' => CourseSpecification::StateActive,
            'credit_units' => 0.00,
            'academic_classification' => CourseSpecification::AcademicClassificationNonCredit,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        CourseComponent::factory()->for($nonCreditSpec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1.00,
            'meeting_pattern' => '1x60',
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);

        $entry = CurriculumEntry::factory()
            ->for($curriculum, 'curriculumVersion')
            ->for($nonCreditSpec, 'courseSpecification')
            ->create(['year_level' => 'First Year', 'term_type' => $term->type]);

        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSIT-1Z',
            'confirmed_count' => 30,
        ]);

        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 25,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'NSTP-1Z',
            'capacity' => 35,
            'state' => Section::StatePlanned,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'NSTP-1Z',
            'expected_count' => 25,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section = app(PrepareClassOffering::class)->execute($term, $section, $registrar, [
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 25]);

        FacultyQualification::factory()->create([
            'faculty_user_id' => $facultyA->id,
            'course_id' => $nonCreditCourse->id,
            'is_active' => true,
        ]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $facultyA,
            actor: $facultyA,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $this->assertTrue(app(ReadyTermPlanningProjection::class)->forTerm($term)['ready']);

        // Registrar marks sole teacher Faculty A as Not teaching (0 units).
        FacultyTermLoadOverride::factory()->create([
            'faculty_user_id' => $facultyA->id,
            'term_id' => $term->id,
            'default_max_units_snapshot' => 0.00,
            'approved_overload_units' => 0.00,
            'authority' => 'Registrar Term Resources',
            'reason' => 'On study leave this Term',
            'is_active' => true,
        ]);

        // Faculty self-projection surfaces 'Not teaching (Registrar)' + reason cleanly.
        $facultyStatus = app(FacultyAvailabilityStatusProjection::class)->forFaculty($term, $facultyA);
        $this->assertTrue($facultyStatus['registrar_not_teaching']);
        $this->assertSame('Not teaching (Registrar)', $facultyStatus['status_label']);
        $this->assertSame('On study leave this Term', $facultyStatus['registrar_not_teaching_reason']);

        // Readiness projection catches uncovered demand BEFORE CP-SAT runs.
        $blockedUncovered = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $this->assertFalse($blockedUncovered['ready']);
        $uncoveredBlocker = collect($blockedUncovered['blockers'])->firstWhere('code', 'faculty_demand_uncovered');
        $this->assertNotNull($uncoveredBlocker);
        $this->assertStringContainsString('Not teaching (Registrar)', $uncoveredBlocker['reason']);

        // Fixed commitment pointing to 0-unit overridden Faculty A also triggers fixed_faculty_cannot_teach.
        $group->update(['delivery_override' => ['fixed_faculty_user_id' => $facultyA->id]]);
        $blockedFixed = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $this->assertFalse($blockedFixed['ready']);
        $fixedBlocker = collect($blockedFixed['blockers'])->firstWhere('code', 'fixed_faculty_cannot_teach');
        $this->assertNotNull($fixedBlocker);
        $this->assertStringContainsString($facultyA->name, $fixedBlocker['reason']);

        // Add Faculty B as qualified + available and remove fixed override -> snapshot excludes Faculty A even on 0-unit course.
        $group->update(['delivery_override' => null]);
        FacultyQualification::factory()->create([
            'faculty_user_id' => $facultyB->id,
            'course_id' => $nonCreditCourse->id,
            'is_active' => true,
        ]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $facultyB,
            actor: $facultyB,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $demand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->sole();
        $this->assertSame([$facultyB->id], collect($demand->source_snapshot['faculty_load_options'])->pluck('faculty_user_id')->all());

        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusQueued,
            'input_snapshot' => [],
        ]);
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($run);

        $demandPayload = collect($snapshot['scheduling_demands'])->sole();
        $this->assertSame([$facultyB->id], $demandPayload['eligible_faculty_user_ids']);
        $this->assertSame([$facultyB->id], collect($demandPayload['faculty_load_options'])->pluck('faculty_user_id')->all());
    }

    #[Test]
    public function same_faculty_required_multi_component_readiness_requires_single_teacher_covering_all_components(): void
    {
        $registrar = $this->registrar();
        $facultyA = $this->faculty();
        $facultyB = $this->faculty();

        [$term, , , $curriculum] = $this->activeTermContext();
        Room::factory()->create(['room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);
        Room::factory()->create(['room_type' => Room::TypeComputerLaboratory, 'capacity' => 40, 'is_active' => true]);

        $course = Course::factory()->create(['code' => 'IT 301']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Systems Integration',
            'state' => CourseSpecification::StateActive,
            'credit_units' => 3.00,
            'same_faculty_default' => true,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $lecComponent = CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1.00,
            'meeting_pattern' => '1x60',
            'room_type_default' => Room::TypeLectureRoom,
            'same_faculty' => true,
            'sequence' => 1,
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => 2.00,
            'meeting_pattern' => '1x120',
            'room_type_default' => Room::TypeComputerLaboratory,
            'same_faculty' => true,
            'sequence' => 2,
        ]);

        $entry = CurriculumEntry::factory()
            ->for($curriculum, 'curriculumVersion')
            ->for($spec, 'courseSpecification')
            ->create(['year_level' => 'Third Year', 'term_type' => $term->type]);
        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSIT-3A',
            'confirmed_count' => 25,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 25,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'IT301-3A',
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ]);
        SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'IT301-3A',
            'expected_count' => 25,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section = app(PrepareClassOffering::class)->execute($term, $section, $registrar, [
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 25]);

        FacultyQualification::factory()->create(['faculty_user_id' => $facultyA->id, 'course_id' => $course->id, 'is_active' => true]);
        FacultyQualification::factory()->create(['faculty_user_id' => $facultyB->id, 'course_id' => $course->id, 'is_active' => true]);

        // Faculty A only has 120 minutes total (08:00-10:00), which satisfies 60 min alone and 120 min alone,
        // but CANNOT cover both 60 + 120 = 180 minutes required when same_faculty_required = true.
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $facultyA,
            actor: $facultyA,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00']],
        );

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $blocked = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $this->assertFalse($blocked['ready']);
        $this->assertNotNull(collect($blocked['blockers'])->firstWhere('code', 'faculty_demand_uncovered'));

        // Even if Faculty B has full availability, pinning Lecture to Faculty A (who lacks combined capacity) must still block!
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $facultyB,
            actor: $facultyB,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );
        SchedulingDemand::query()
            ->where('term_offering_id', $offering->id)
            ->where('course_component_id', $lecComponent->id)
            ->update(['fixed_faculty_user_id' => $facultyA->id]);

        $fixedBlocked = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $this->assertFalse($fixedBlocked['ready']);
        $this->assertNotNull(collect($fixedBlocked['blockers'])->firstWhere('code', 'fixed_faculty_no_usable_time'));
    }

    #[Test]
    public function snapshot_merges_non_conflicting_commitments_preserves_zero_units_and_validator_allows_multi_meeting_pin(): void
    {
        $registrar = $this->registrar();
        $faculty = $this->faculty();
        [$term, , , $curriculum] = $this->activeTermContext();

        $room = Room::factory()->create(['room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);
        $course = Course::factory()->create(['code' => 'CS 202']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'credit_units' => 0.00,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $component = CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'meeting_pattern' => '2x90',
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create(['term_type' => $term->type]);
        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSIT-2C',
            'confirmed_count' => 25,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 25,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'CS202-2C',
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'CS202-2C',
            'expected_count' => 25,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section = app(PrepareClassOffering::class)->execute($term, $section, $registrar, [
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 25]);

        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $course->id, 'is_active' => true]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        // Two non-conflicting commitments on the same section (fixed teacher+time and fixed room).
        SchedulingCommitment::factory()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => null,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'recorded_by' => $registrar->id,
        ]);
        SchedulingCommitment::factory()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'faculty_user_id' => null,
            'room_id' => $room->id,
            'day_of_week' => null,
            'starts_at' => null,
            'ends_at' => null,
            'recorded_by' => $registrar->id,
        ]);

        // Also ensure numeric 0 term default_max_units never drops to null via ->filter().
        $term->update(['default_max_units' => 0]);

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $demand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->sole();
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusQueued,
            'input_snapshot' => [],
        ]);
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($run);
        $demandPayload = collect($snapshot['scheduling_demands'])->sole();

        $this->assertSame($faculty->id, $demandPayload['fixed_faculty_user_id']);
        $this->assertSame($room->id, $demandPayload['fixed_room_id']);
        $this->assertSame(1, $demandPayload['fixed_day_of_week']);
        $this->assertSame('08:00:00', $demandPayload['fixed_start_time']);
        $this->assertSame('0.00', $snapshot['faculty'][0]['max_allowed_units']);

        $slotMon = collect($snapshot['time_slots'])->firstWhere('time_block_key', 'D1-0800');
        $slotWed = collect($snapshot['time_slots'])->firstWhere('time_block_key', 'D3-0800');
        $baseRow = [
            'scheduling_demand_id' => $demand->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $group->id,
            'cohort_or_student_group_id' => $demandPayload['cohort_or_student_group_id'],
            'cohort_or_student_group_ids' => $demandPayload['cohort_or_student_group_ids'],
            'subject_id' => $course->id,
            'course_component_id' => $component->id,
            'faculty_id' => $faculty->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'meeting_pattern' => '2x90',
            'assignment_status' => 'ok',
            'violations' => [],
            'warnings' => [],
            'scores' => [],
            'soft_constraint_scores' => [],
        ];

        $valid = app(ScheduleAssignmentValidationService::class)->validateCandidateAssignments($run, $snapshot, [
            [
                ...$baseRow,
                'meeting_sequence' => 1,
                'day' => 1,
                'day_of_week' => 1,
                'start_time' => '08:00:00',
                'starts_at' => '08:00:00',
                'end_time' => '09:30:00',
                'ends_at' => '09:30:00',
                'time_slot_id' => $slotMon['time_slot_id'],
                'time_block_key' => 'D1-0800',
                'time_block_reference' => 'D1-0800',
            ],
            [
                ...$baseRow,
                'meeting_sequence' => 2,
                'day' => 3,
                'day_of_week' => 3,
                'start_time' => '08:00:00',
                'starts_at' => '08:00:00',
                'end_time' => '09:30:00',
                'ends_at' => '09:30:00',
                'time_slot_id' => $slotWed['time_slot_id'],
                'time_block_key' => 'D3-0800',
                'time_block_reference' => 'D3-0800',
            ],
        ]);
        $this->assertTrue($valid->passes(), json_encode($valid->findings(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function edit_room_blocks_deactivating_or_shrinking_published_room_and_stales_unreviewed_runs(): void
    {
        $this->registrar();
        [$term, $package, , $curriculum] = $this->activeTermContext();

        $room = Room::factory()->create([
            'code' => 'RM-GUARD-1',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);

        $course = Course::factory()->create(['code' => 'IT 401']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create(['term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['expected_count' => 30]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $spec->id,
            'code' => 'IT401-4A',
            'class_reference' => 'IT401-4A',
            'capacity' => 35,
        ]);
        SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'IT401-4A',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);

        $publishedRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusPublished,
            'candidate_state' => 'Accepted',
        ]);
        $draftRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'UnderReview',
            'published_at' => null,
            'published_by' => null,
            'publication_version' => null,
        ]);

        $version = PublishedTimetableVersion::factory()->for($term)->create([
            'schedule_run_id' => $publishedRun->id,
            'state' => PublishedTimetableVersion::StatePublished,
        ]);
        PublishedTimetableMeeting::factory()->create([
            'published_timetable_version_id' => $version->id,
            'section_id' => $section->id,
            'room_id' => $room->id,
        ]);

        // 1. Deactivating room used by active published meeting is blocked naming affected class.
        Livewire::test(EditRoom::class, ['record' => $room->id])
            ->set('data.is_active', false)
            ->call('save')
            ->assertHasErrors(['data.is_active'])
            ->assertSee('IT401-4A');
        $this->assertTrue((bool) $room->fresh()->is_active);
        $this->assertSame('UnderReview', $draftRun->fresh()->candidate_state);

        // 2. Shrinking capacity below expected count (30) is blocked naming affected class.
        Livewire::test(EditRoom::class, ['record' => $room->id])
            ->set('data.capacity', 25)
            ->call('save')
            ->assertHasErrors(['data.capacity'])
            ->assertSee('IT401-4A');
        $this->assertSame(40, (int) $room->fresh()->capacity);
        $this->assertSame('UnderReview', $draftRun->fresh()->candidate_state);

        // 3. Reducing capacity safely (>= 30) succeeds and marks unreviewed run Stale while keeping published run Accepted.
        Livewire::test(EditRoom::class, ['record' => $room->id])
            ->set('data.capacity', 35)
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(35, (int) $room->fresh()->capacity);
        $this->assertSame('Stale', $draftRun->fresh()->candidate_state);
        $this->assertSame('Accepted', $publishedRun->fresh()->candidate_state);
    }

    #[Test]
    public function term_classes_sync_updates_section_course_specification_without_mutating_active_curriculum_entry(): void
    {
        $registrar = $this->registrar();
        [$term, , , $activeCurriculum] = $this->activeTermContext();

        Room::factory()->create(['room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);
        $faculty = $this->faculty();
        $course = Course::factory()->create(['code' => 'CS 310']);
        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $course->id, 'is_active' => true]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        $specV1 = CourseSpecification::factory()->for($course)->create([
            'title' => 'Algorithms v1',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        CourseComponent::factory()->for($specV1)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1.50,
            'meeting_pattern' => '1x90',
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);

        $activeEntry = CurriculumEntry::factory()
            ->for($activeCurriculum, 'curriculumVersion')
            ->for($specV1, 'courseSpecification')
            ->create(['year_level' => 'Third Year', 'term_type' => $term->type]);

        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $activeCurriculum->id,
            'reference' => 'BSIT-3C',
            'confirmed_count' => 25,
        ]);
        $offering = TermOffering::factory()->for($term)->for($activeEntry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 25,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'course_specification_id' => $specV1->id,
            'code' => 'CS310-3C',
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ]);
        SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'CS310-3C',
            'expected_count' => 25,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section = app(PrepareClassOffering::class)->execute($term, $section, $registrar, [
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        $section = app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 25]);
        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);

        $draftRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'UnderReview',
            'published_at' => null,
            'published_by' => null,
            'publication_version' => null,
        ]);

        // Retire v1 and activate newer v2 on the course while the ACTIVE CurriculumVersion still points to v1.
        $specV1->update(['state' => CourseSpecification::StateRetired]);
        $specV2 = CourseSpecification::factory()->for($course)->create([
            'title' => 'Algorithms v2',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $v2Component = CourseComponent::factory()->for($specV2)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'meeting_pattern' => '2x90',
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);

        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'classes'])
            ->test(TermClasses::class)
            ->callTableAction('editClass', $section, [
                'capacity' => 35,
                'delivery_mode' => TermOffering::ModalityFaceToFace,
                'lecture_room_type' => Room::TypeLectureRoom,
                'sync_latest_curriculum' => true,
                'cohorts' => [['term_cohort_id' => $cohort->id, 'expected_count' => 25]],
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Class and room requirements updated');

        // Published ACTIVE CurriculumEntry stays immutable!
        $this->assertSame($specV1->id, (int) $activeEntry->fresh()->course_specification_id);
        // Only the Section adopts the synced CourseSpecification revision.
        $this->assertSame($specV2->id, (int) $section->fresh()->course_specification_id);
        // Unreviewed run is marked Stale and demands regenerate cleanly from v2.
        $this->assertSame('Stale', $draftRun->fresh()->candidate_state);

        $regeneratedDemand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->sole();
        $this->assertSame($v2Component->id, (int) $regeneratedDemand->course_component_id);
        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $regeneratedDemand->validation_state);
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
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();
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
        $suffix = ++$this->seq;
        $term = Term::factory()->create([
            'label' => 'Guard Term '.$suffix,
            'state' => Term::StateActive,
            'default_max_units' => 24.00,
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        foreach ([1, 2, 3] as $day) {
            TermTeachingGridRow::factory()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
            ]);
        }

        $program = Program::factory()->create(['code' => 'P93G'.$suffix]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'Guard Curriculum '.$suffix,
            'state' => CurriculumVersion::StateActive,
        ]);

        return [$term, $package, $program, $curriculum];
    }
}
