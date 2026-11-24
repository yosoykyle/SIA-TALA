<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\RecordTermCohort;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\ScheduleAssignmentValidationService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\Rooms\Pages\EditRoom;
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
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\RoomFeature;
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
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93SolverAndPinParityTest extends TestCase
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
    public function json_room_type_override_and_required_room_feature_keys_are_enforced_in_readiness_and_demand_generation(): void
    {
        $registrar = $this->registrar();
        $faculty = $this->faculty();
        [$term, , , $curriculum] = $this->activeTermContext();

        $lectureRoom = Room::factory()->create([
            'code' => 'RM-C1-LEC',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $labRoom = Room::factory()->create([
            'code' => 'RM-C1-LAB',
            'room_type' => Room::TypeComputerLaboratory,
            'capacity' => 40,
            'is_active' => true,
        ]);

        $course = Course::factory()->create(['code' => 'IT 210']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Networking Fundamentals',
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
            'required_room_feature_keys' => [],
            'same_faculty' => true,
            'sequence' => 1,
        ]);
        $labComponent = CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => 2.00,
            'meeting_pattern' => '1x120',
            'room_type_default' => Room::TypeComputerLaboratory,
            'required_room_feature_keys' => ['NETWORK_RACK'],
            'same_faculty' => true,
            'sequence' => 2,
        ]);

        $entry = CurriculumEntry::factory()
            ->for($curriculum, 'curriculumVersion')
            ->for($spec, 'courseSpecification')
            ->create(['year_level' => 'Second Year', 'term_type' => $term->type]);

        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSIT-2N',
            'confirmed_count' => 30,
        ]);

        $jsonOverride = json_encode([
            (string) $lecComponent->id => Room::TypeLectureRoom,
            (string) $labComponent->id => Room::TypeComputerLaboratory,
            CourseComponent::TypeLecture => Room::TypeLectureRoom,
            CourseComponent::TypeLaboratory => Room::TypeComputerLaboratory,
        ], JSON_THROW_ON_ERROR);

        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'room_type_override' => $jsonOverride,
            'expected_count' => 30,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'course_specification_id' => $spec->id,
            'code' => 'IT210-2N',
            'capacity' => 35,
            'state' => Section::StatePlanned,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'IT210-2N',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section = app(PrepareClassOffering::class)->execute($term, $section, $registrar, [
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        app(ConfirmClassOffering::class)->execute($section, $registrar, [$cohort->id => 30]);

        FacultyQualification::factory()->create([
            'faculty_user_id' => $faculty->id,
            'course_id' => $course->id,
            'is_active' => true,
        ]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        // Lab room lacks NETWORK_RACK feature -> readiness and demand generation must block.
        $group->update(['delivery_override' => ['fixed_room_id' => $labRoom->id]]);
        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);

        $readinessWithoutFeature = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $this->assertFalse($readinessWithoutFeature['ready']);
        $roomBlocker = collect($readinessWithoutFeature['blockers'])->firstWhere('code', 'rooms_unsuitable');
        $this->assertNotNull($roomBlocker);
        $this->assertStringNotContainsString('{', $roomBlocker['reason']);

        $labDemand = SchedulingDemand::query()
            ->where('term_offering_id', $offering->id)
            ->where('course_component_id', $labComponent->id)
            ->sole();
        $this->assertSame(SchedulingDemand::ValidationActionRequired, $labDemand->validation_state);
        $findingKeys = collect($labDemand->readiness_findings)->pluck('key')->all();
        $this->assertContains('missing_suitable_room', $findingKeys);
        $this->assertContains('fixed_room_not_suitable', $findingKeys);

        // Clear fixed_room_id override and attach NETWORK_RACK feature to the computer lab room.
        $group->update(['delivery_override' => null]);
        RoomFeature::factory()->for($labRoom, 'room')->create([
            'feature_key' => 'NETWORK_RACK',
        ]);

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $readinessWithFeature = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $this->assertTrue($readinessWithFeature['ready'], json_encode($readinessWithFeature['blockers'], JSON_THROW_ON_ERROR));

        $lecDemand = SchedulingDemand::query()
            ->where('term_offering_id', $offering->id)
            ->where('course_component_id', $lecComponent->id)
            ->sole();
        $labDemand->refresh();

        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $lecDemand->validation_state);
        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $labDemand->validation_state);
        $this->assertSame($lectureRoom->room_type, $lecDemand->source_snapshot['room_type_requirement']);
        $this->assertSame($labRoom->room_type, $labDemand->source_snapshot['room_type_requirement']);
    }

    #[Test]
    public function component_and_sequence_scoped_registrar_pins_isolate_components_and_sequences_through_publish(): void
    {
        $registrar = $this->registrar();
        $facultyOne = $this->faculty('Dr. Pin One');
        $facultyTwo = $this->faculty('Prof. Pin Two');
        [$term, $package, , $curriculum] = $this->activeTermContext();

        $lecRoomOne = Room::factory()->create([
            'code' => 'RM-PIN-101',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 45,
            'is_active' => true,
        ]);
        $lecRoomTwo = Room::factory()->create([
            'code' => 'RM-PIN-102',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 45,
            'is_active' => true,
        ]);
        $labRoom = Room::factory()->create([
            'code' => 'LAB-PIN-201',
            'room_type' => Room::TypeComputerLaboratory,
            'capacity' => 45,
            'is_active' => true,
        ]);

        // Class 1: Lec + Lab (1x60 Lecture + 1x120 Lab)
        $lecLabCourse = Course::factory()->create(['code' => 'IT 220']);
        $lecLabSpec = CourseSpecification::factory()->for($lecLabCourse)->create([
            'title' => 'Database Systems',
            'state' => CourseSpecification::StateActive,
            'credit_units' => 3.00,
            'same_faculty_default' => false,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $lecComponent = CourseComponent::factory()->for($lecLabSpec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1.00,
            'meeting_pattern' => '1x60',
            'room_type_default' => Room::TypeLectureRoom,
            'same_faculty' => false,
            'sequence' => 1,
        ]);
        $labComponent = CourseComponent::factory()->for($lecLabSpec)->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => 2.00,
            'meeting_pattern' => '1x120',
            'room_type_default' => Room::TypeComputerLaboratory,
            'same_faculty' => false,
            'sequence' => 2,
        ]);

        // Class 2: Single-component 2x90
        $multiCourse = Course::factory()->create(['code' => 'IT 221']);
        $multiSpec = CourseSpecification::factory()->for($multiCourse)->create([
            'title' => 'Operating Systems',
            'state' => CourseSpecification::StateActive,
            'credit_units' => 3.00,
            'same_faculty_default' => true,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $multiComponent = CourseComponent::factory()->for($multiSpec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'meeting_pattern' => '2x90',
            'room_type_default' => Room::TypeLectureRoom,
            'same_faculty' => true,
            'sequence' => 1,
        ]);

        $lecLabEntry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($lecLabSpec, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        $multiEntry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($multiSpec, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_type' => $term->type]);

        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSIT-2P',
            'confirmed_count' => 30,
        ]);

        $lecLabOffering = TermOffering::factory()->for($term)->for($lecLabEntry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 30,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $lecLabSection = Section::factory()->for($lecLabOffering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $lecLabSpec->id,
            'code' => 'IT220-2P',
            'capacity' => 35,
            'state' => Section::StatePlanned,
        ]);
        $lecLabGroup = SectionDeliveryGroup::factory()->for($lecLabSection)->create([
            'name' => 'IT220-2P',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $lecLabSection = app(PrepareClassOffering::class)->execute($term, $lecLabSection, $registrar, [
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        app(ConfirmClassOffering::class)->execute($lecLabSection, $registrar, [$cohort->id => 30]);

        $multiOffering = TermOffering::factory()->for($term)->for($multiEntry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 30,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $multiSection = Section::factory()->for($multiOffering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $multiSpec->id,
            'code' => 'IT221-2P',
            'capacity' => 35,
            'state' => Section::StatePlanned,
        ]);
        $multiGroup = SectionDeliveryGroup::factory()->for($multiSection)->create([
            'name' => 'IT221-2P',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $multiSection = app(PrepareClassOffering::class)->execute($term, $multiSection, $registrar, [
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        app(ConfirmClassOffering::class)->execute($multiSection, $registrar, [$cohort->id => 30]);

        foreach ([$lecLabCourse, $multiCourse] as $course) {
            FacultyQualification::factory()->create(['faculty_user_id' => $facultyOne->id, 'course_id' => $course->id, 'is_active' => true]);
            FacultyQualification::factory()->create(['faculty_user_id' => $facultyTwo->id, 'course_id' => $course->id, 'is_active' => true]);
        }

        foreach ([$facultyOne, $facultyTwo] as $teacher) {
            app(RecordFacultyAvailabilityDeclaration::class)->execute(
                term: $term,
                faculty: $teacher,
                actor: $teacher,
                availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
            );
        }

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);

        $lecDemand = SchedulingDemand::query()->where('term_offering_id', $lecLabOffering->id)->where('course_component_id', $lecComponent->id)->sole();
        $labDemand = SchedulingDemand::query()->where('term_offering_id', $lecLabOffering->id)->where('course_component_id', $labComponent->id)->sole();
        $multiDemand = SchedulingDemand::query()->where('term_offering_id', $multiOffering->id)->where('course_component_id', $multiComponent->id)->sole();

        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'UnderReview',
            'input_snapshot' => [],
            'requested_by' => $registrar->id,
            'published_by' => null,
            'published_at' => null,
            'publication_version' => null,
        ]);

        app(ScheduleSolverSnapshotService::class)->captureForRun($run);
        $run->refresh();

        $lecRow = CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $lecDemand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $facultyOne->id,
            'room_id' => $lecRoomOne->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'time_block_key' => 'D1-0800',
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => ['total' => 100],
            'change_type' => 'Generated',
        ]);
        $labRow = CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $labDemand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $facultyTwo->id,
            'room_id' => $labRoom->id,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'time_block_key' => 'D2-1000',
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => ['total' => 100],
            'change_type' => 'Generated',
        ]);
        $m1Row = CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $multiDemand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $facultyOne->id,
            'room_id' => $lecRoomOne->id,
            'day_of_week' => 1,
            'starts_at' => '10:00:00',
            'ends_at' => '11:30:00',
            'time_block_key' => 'D1-1000',
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => ['total' => 100],
            'change_type' => 'Generated',
        ]);
        $m2Row = CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $multiDemand->id,
            'meeting_sequence' => 2,
            'faculty_user_id' => $facultyOne->id,
            'room_id' => $lecRoomTwo->id,
            'day_of_week' => 3,
            'starts_at' => '13:00:00',
            'ends_at' => '14:30:00',
            'time_block_key' => 'D3-1300',
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => ['total' => 100],
            'change_type' => 'Generated',
        ]);

        // 1. Pin Lecture component of Lec+Lab section and Meeting 2 of 2x90 section.
        $component = Livewire::actingAs($registrar)
            ->test(TermTimetable::class, ['termId' => $term->id])
            ->call('togglePinMeeting', $lecRow->id)
            ->assertHasNoErrors()
            ->call('togglePinMeeting', $m2Row->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('scheduling_commitments', [
            'term_id' => $term->id,
            'section_id' => $lecLabSection->id,
            'authority_reference' => "REGISTRAR-PIN:D{$lecDemand->id}:M1",
        ]);
        $this->assertDatabaseHas('scheduling_commitments', [
            'term_id' => $term->id,
            'section_id' => $multiSection->id,
            'authority_reference' => "REGISTRAR-PIN:D{$multiDemand->id}:M2",
        ]);

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $lecDemand->refresh();
        $labDemand->refresh();
        $multiDemand->refresh();

        // Lec is pinned; Lab is untouched and ready!
        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $lecDemand->validation_state);
        $this->assertSame(1, (int) $lecDemand->fixed_day_of_week);
        $this->assertSame('08:00:00', (string) $lecDemand->fixed_start_time);
        $this->assertSame($lecRoomOne->id, (int) $lecDemand->fixed_room_id);

        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $labDemand->validation_state);
        $this->assertNull($labDemand->fixed_day_of_week);
        $this->assertNull($labDemand->fixed_start_time);
        $this->assertNull($labDemand->fixed_room_id);

        // Meeting 2 pin on 2x90 demand has fixed_meeting_sequence = 2 and does not leak onto Meeting 1!
        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $multiDemand->validation_state);
        $this->assertSame(2, (int) ($multiDemand->source_snapshot['fixed_meeting_sequence'] ?? 0));

        // 2. Now ALSO pin Meeting 1 of 2x90 on Monday 10:00 (different day and room from Meeting 2 on Wednesday 13:00).
        $component
            ->call('togglePinMeeting', $m1Row->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('scheduling_commitments', [
            'term_id' => $term->id,
            'section_id' => $multiSection->id,
            'authority_reference' => "REGISTRAR-PIN:D{$multiDemand->id}:M1",
        ]);

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $multiDemand->refresh();
        $this->assertSame(SchedulingDemand::ValidationReadyForReview, $multiDemand->validation_state, json_encode($multiDemand->readiness_findings, JSON_THROW_ON_ERROR));

        $currentSnapshot = app(ScheduleSolverSnapshotService::class)->currentForRun($run);
        $multiPayload = collect($currentSnapshot['scheduling_demands'])->firstWhere('scheduling_demand_id', $multiDemand->id);
        $this->assertNotNull($multiPayload);
        $this->assertCount(2, $multiPayload['fixed_meetings'] ?? []);

        // 3. Publish directly via SchedulePublishService::publish() while scoped pins are active in DB.
        $acceptedRun = app(ReviewTimetableCandidate::class)->accept($run, $registrar, 'Verified scoped pins');
        $publishedRun = app(SchedulePublishService::class)->publish($acceptedRun, $registrar, 'Published with scoped pins');

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $publishedRun->status);
    }

    #[Test]
    public function single_component_multi_meeting_readiness_and_same_faculty_validation_are_enforced(): void
    {
        $registrar = $this->registrar();
        $facultyA = $this->faculty('Dr. Short Window');
        $facultyB = $this->faculty('Prof. Second Teacher');
        [$term, $package, , $curriculum] = $this->activeTermContext();

        $room = Room::factory()->create([
            'code' => 'RM-H3-101',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 45,
            'is_active' => true,
        ]);

        $course = Course::factory()->create(['code' => 'CS 250']);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Theory of Computation',
            'state' => CourseSpecification::StateActive,
            'credit_units' => 3.00,
            'same_faculty_default' => true,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $component = CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'meeting_pattern' => '2x90',
            'room_type_default' => Room::TypeLectureRoom,
            'same_faculty' => true,
            'sequence' => 1,
        ]);

        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSCS-2H',
            'confirmed_count' => 25,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 25,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $spec->id,
            'code' => 'CS250-2H',
            'capacity' => 35,
            'state' => Section::StatePlanned,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'CS250-2H',
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

        // Faculty A only has 90 minutes (Mon 08:00-09:30), which cannot pack two 90-minute meetings (2x90 = 180m).
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $facultyA,
            actor: $facultyA,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:30']],
        );

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $blockedReadiness = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $this->assertFalse($blockedReadiness['ready']);
        $this->assertNotNull(collect($blockedReadiness['blockers'])->firstWhere('code', 'faculty_demand_uncovered'));

        // Expand Faculty A and qualify Faculty B with full availability so readiness passes.
        FacultyQualification::factory()->create(['faculty_user_id' => $facultyB->id, 'course_id' => $course->id, 'is_active' => true]);
        foreach ([$facultyA, $facultyB] as $teacher) {
            app(RecordFacultyAvailabilityDeclaration::class)->execute(
                term: $term,
                faculty: $teacher,
                actor: $teacher,
                correctionReason: $teacher->is($facultyA) ? 'Expanded teaching availability.' : null,
                availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
            );
        }

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $this->assertTrue(app(ReadyTermPlanningProjection::class)->forTerm($term)['ready']);

        $demand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->sole();
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusQueued,
            'input_snapshot' => [],
        ]);
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($run);
        $demandPayload = collect($snapshot['scheduling_demands'])->sole();

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
            'room_id' => $room->id,
            'meeting_pattern' => '2x90',
            'assignment_status' => 'ok',
            'violations' => [],
            'warnings' => [],
            'scores' => [],
            'soft_constraint_scores' => [],
        ];

        // Assigning Meeting 1 to Faculty A and Meeting 2 to Faculty B on a single-component 2x90 demand with same_faculty_required = true must fail!
        $invalid = app(ScheduleAssignmentValidationService::class)->validateCandidateAssignments($run, $snapshot, [
            [
                ...$baseRow,
                'meeting_sequence' => 1,
                'faculty_id' => $facultyA->id,
                'faculty_user_id' => $facultyA->id,
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
                'faculty_id' => $facultyB->id,
                'faculty_user_id' => $facultyB->id,
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

        $this->assertFalse($invalid->passes());
        $this->assertContains('same_faculty_mismatch', collect($invalid->findings())->pluck('code')->all());
    }

    #[Test]
    public function edit_room_blocks_room_type_change_on_published_room_and_timetable_filters_ineligible_teachers(): void
    {
        $registrar = $this->registrar();
        $eligibleFaculty = $this->faculty('Dr. Eligible Teacher');
        $zeroUnitFaculty = $this->faculty('Prof. Zero Unit');
        $cannotTeachFaculty = $this->faculty('Prof. Cannot Teach');
        [$term, $package, , $curriculum] = $this->activeTermContext();

        $publishedRoom = Room::factory()->create([
            'code' => 'RM-L9-PUB',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $unassignedRoom = Room::factory()->create([
            'code' => 'RM-L9-FREE',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);

        $course = Course::factory()->create(['code' => 'IT 450']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create(['term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['expected_count' => 30]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $spec->id,
            'code' => 'IT450-4A',
            'class_reference' => 'IT450-4A',
            'capacity' => 35,
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
            'room_id' => $publishedRoom->id,
            'faculty_user_id' => $eligibleFaculty->id,
        ]);

        // 1. Changing room_type on a room assigned to an active published meeting is blocked.
        Livewire::test(EditRoom::class, ['record' => $publishedRoom->id])
            ->set('data.room_type', Room::TypeComputerLaboratory)
            ->call('save')
            ->assertHasErrors(['data.room_type'])
            ->assertSee('IT450-4A');
        $this->assertSame(Room::TypeLectureRoom, (string) $publishedRoom->fresh()->room_type);
        $this->assertSame('UnderReview', $draftRun->fresh()->candidate_state);

        // 2. Changing room_type on an unassigned active room succeeds and marks active unreviewed runs Stale.
        Livewire::test(EditRoom::class, ['record' => $unassignedRoom->id])
            ->set('data.room_type', Room::TypeComputerLaboratory)
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(Room::TypeComputerLaboratory, (string) $unassignedRoom->fresh()->room_type);
        $this->assertSame('Stale', $draftRun->fresh()->candidate_state);

        // 3. TermTimetable filters out 0-unit overridden and Cannot-teach faculty from interactive teacher dropdown.
        foreach ([$eligibleFaculty, $zeroUnitFaculty, $cannotTeachFaculty] as $teacher) {
            FacultyQualification::factory()->create([
                'faculty_user_id' => $teacher->id,
                'course_id' => $course->id,
                'is_active' => true,
            ]);
        }

        FacultyTermLoadOverride::factory()->create([
            'faculty_user_id' => $zeroUnitFaculty->id,
            'term_id' => $term->id,
            'default_max_units_snapshot' => 0.00,
            'approved_overload_units' => 0.00,
            'authority' => 'Registrar Term Resources',
            'reason' => 'Not teaching this Term',
            'is_active' => true,
        ]);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $cannotTeachFaculty,
            actor: $cannotTeachFaculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeCannotTeach,
        );

        $timetableComponent = Livewire::actingAs($registrar)
            ->test(TermTimetable::class, ['termId' => $term->id]);

        $interactiveContext = $timetableComponent->viewData('interactiveContext');
        $teacherIdsForCourse = collect($interactiveContext['qualifiedTeachersByCourse']['IT 450'] ?? [])
            ->pluck('id')
            ->all();

        $this->assertSame([$eligibleFaculty->id], $teacherIdsForCourse);
        $this->assertSame('gray', $timetableComponent->instance()->cancelActiveRunAction()->getColor());
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

    private function faculty(?string $name = null): User
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $attributes = ['status' => User::StatusActive];
        if ($name !== null) {
            $attributes['name'] = $name;
        }
        $user = User::factory()->create($attributes);
        $user->assignRole(User::StaffRoleFaculty);

        return $user;
    }

    /** @return array{Term, TermCalendarPackage, Program, CurriculumVersion} */
    private function activeTermContext(): array
    {
        $suffix = ++$this->seq;
        $term = Term::factory()->create([
            'label' => 'Solver Parity Term '.$suffix,
            'state' => Term::StateActive,
            'default_max_units' => 24.00,
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

        $program = Program::factory()->create(['code' => 'P93S'.$suffix]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'Solver Parity Curriculum '.$suffix,
            'state' => CurriculumVersion::StateActive,
        ]);

        return [$term, $package, $program, $curriculum];
    }
}
