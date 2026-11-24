<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\TermTimetable;
use App\Models\AcademicYear;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\Program;
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
use Tests\TestCase;

class Issue93InteractiveTimetableTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function draft_timetable_renders_full_width_interactive_controls_badges_and_hydrated_context(): void
    {
        $fixture = $this->createDraftTimetableFixture();

        $component = Livewire::actingAs($fixture['registrar'])
            ->test(TermTimetable::class, ['termId' => $fixture['term']->id]);

        $component
            ->assertSee('Draft candidate · Editable')
            ->assertSee('Publish official timetable')
            ->assertSee('All (Whole Term)')
            ->assertSee('By section')
            ->assertSee('By teacher')
            ->assertSee('By room')
            ->assertSee('IT 101')
            ->assertSee('BSIT-1A')
            ->assertSee('LEC')
            ->assertSee('LAB')
            ->assertSee('RM-201')
            ->assertSee('LAB-301')
            ->assertSee($fixture['facultyOne']->name)
            ->assertSee($fixture['facultyTwo']->name)
            ->assertSee('draggable="true"', false)
            ->assertSee('tala-navigation-compact', false)
            ->assertSee('tala-interactive-view-tabs', false)
            ->assertSee('tala-interactive-format-tabs', false)
            ->assertSee('fi-input-wrp', false)
            ->assertSee('fi-select-input', false)
            ->assertDontSee('tala-interactive-view-pills', false)
            ->assertDontSee('tala-view-pill', false)
            ->assertDontSee('tala-interactive-format-switcher', false)
            ->assertDontSee('tala-format-btn', false)
            ->assertDontSee('tala-studio-dock', false)
            ->assertDontSee('Review assignments');

        $interactiveContext = $component->viewData('interactiveContext');

        $this->assertTrue($interactiveContext['editable']);
        $this->assertFalse($interactiveContext['isOfficial']);
        $this->assertSame('Draft candidate · Editable', $interactiveContext['statusPillLabel']);
        $this->assertArrayHasKey(1, $interactiveContext['teachingGrid']);
        $this->assertArrayHasKey($fixture['facultyOne']->id, $interactiveContext['teacherAvailability']);
        $this->assertArrayHasKey('IT 101', $interactiveContext['qualifiedTeachersByCourse']);
        $this->assertNotEmpty($interactiveContext['rooms']);
    }

    #[Test]
    public function registrar_can_drag_move_draft_meeting_without_memo_and_conflicts_are_rejected(): void
    {
        $fixture = $this->createDraftTimetableFixture();
        $lectureRow = $fixture['lectureRow'];

        // Move lecture meeting to a valid, conflict-free slot within Dr. Elena Cruz's Monday window.
        $component = Livewire::actingAs($fixture['registrar'])
            ->test(TermTimetable::class, ['termId' => $fixture['term']->id])
            ->call('moveCandidateMeeting', $lectureRow->id, 1, '10:00')
            ->assertHasNoErrors()
            ->assertNotified('Meeting slot updated');

        $newRunId = $component->get('selectedRunId');
        $this->assertNotSame($fixture['run']->id, $newRunId);

        $movedRow = CandidateScheduleRow::query()
            ->where('schedule_run_id', $newRunId)
            ->where('scheduling_demand_id', $fixture['lectureDemand']->id)
            ->where('meeting_sequence', 1)
            ->sole();

        $this->assertSame(1, (int) $movedRow->day_of_week);
        $this->assertSame('10:00:00', (string) $movedRow->starts_at);
        $this->assertSame('11:00:00', (string) $movedRow->ends_at);
        $this->assertSame($fixture['roomLectureOne']->id, (int) $movedRow->room_id);
        $this->assertSame($fixture['facultyOne']->id, (int) $movedRow->faculty_user_id);

        // Moving into Tuesday afternoon (outside Dr. Elena Cruz's 08:00-12:00 Tuesday window) must fail cleanly.
        $component
            ->call('moveCandidateMeeting', $movedRow->id, 2, '14:00')
            ->assertHasErrors()
            ->assertNotified('Cannot move meeting to this slot');

        // Moving into Wednesday 10:00 (section BSIT-1A already has Lab at Wed 10:00-12:00) must fail cleanly.
        $component
            ->call('moveCandidateMeeting', $movedRow->id, 3, '10:00')
            ->assertHasErrors()
            ->assertNotified('Cannot move meeting to this slot');
    }

    #[Test]
    public function registrar_can_toggle_pin_and_inline_edit_draft_meeting_without_approval_memo(): void
    {
        $fixture = $this->createDraftTimetableFixture();
        $lectureRow = $fixture['lectureRow'];

        $component = Livewire::actingAs($fixture['registrar'])
            ->test(TermTimetable::class, ['termId' => $fixture['term']->id])
            ->call('togglePinMeeting', $lectureRow->id)
            ->assertHasNoErrors()
            ->assertNotified('Slot locked');

        $this->assertTrue((bool) data_get($lectureRow->fresh()->scores, 'pinned', false));
        $this->assertDatabaseHas('scheduling_commitments', [
            'term_id' => $fixture['term']->id,
            'section_id' => $fixture['section']->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'authority_reference' => 'REGISTRAR-PIN',
        ]);

        // Unpin removes both pinned marker and commitment without requiring a memo.
        $component
            ->call('togglePinMeeting', $lectureRow->id)
            ->assertHasNoErrors()
            ->assertNotified('Slot unlocked');

        $this->assertFalse((bool) data_get($lectureRow->fresh()->scores, 'pinned', false));
        $this->assertDatabaseMissing('scheduling_commitments', [
            'term_id' => $fixture['term']->id,
            'section_id' => $fixture['section']->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'authority_reference' => 'REGISTRAR-PIN',
        ]);

        // Direct inline edit changes day, start time, room, and qualified teacher in one call.
        $component
            ->call(
                'updateCandidateMeeting',
                $lectureRow->id,
                3,
                '08:00',
                $fixture['roomLectureTwo']->id,
                $fixture['facultyTwo']->id,
            )
            ->assertHasNoErrors()
            ->assertNotified('Meeting schedule updated');

        $updatedRunId = $component->get('selectedRunId');
        $updatedRow = CandidateScheduleRow::query()
            ->where('schedule_run_id', $updatedRunId)
            ->where('scheduling_demand_id', $fixture['lectureDemand']->id)
            ->where('meeting_sequence', 1)
            ->sole();

        $this->assertSame(3, (int) $updatedRow->day_of_week);
        $this->assertSame('08:00:00', (string) $updatedRow->starts_at);
        $this->assertSame('09:00:00', (string) $updatedRow->ends_at);
        $this->assertSame($fixture['roomLectureTwo']->id, (int) $updatedRow->room_id);
        $this->assertSame($fixture['facultyTwo']->id, (int) $updatedRow->faculty_user_id);
    }

    #[Test]
    public function one_step_publish_accepts_and_locks_official_timetable_against_draft_edits(): void
    {
        $fixture = $this->createDraftTimetableFixture();
        $run = $fixture['run'];
        $lectureRow = $fixture['lectureRow'];

        // Pin one meeting prior to 1-step publish to verify pin commitments do not break publication.
        $component = Livewire::actingAs($fixture['registrar'])
            ->test(TermTimetable::class, ['termId' => $fixture['term']->id])
            ->call('togglePinMeeting', $lectureRow->id)
            ->assertActionVisible('publishOfficialTimetable')
            ->callAction('publishOfficialTimetable', [
                'publication_note' => 'Verified and ready for student and faculty release.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Official timetable published');

        $run->refresh();
        $this->assertSame(ScheduleGenerationRun::StatusPublished, $run->status);
        $this->assertSame('Accepted', $run->candidate_state);
        $this->assertSame($fixture['registrar']->id, (int) $run->candidate_reviewed_by);
        $this->assertNotNull($run->candidate_reviewed_at);

        $publishedVersion = PublishedTimetableVersion::query()
            ->where('term_id', $fixture['term']->id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->sole();

        $this->assertSame($run->id, (int) $publishedVersion->schedule_run_id);

        // Reloading the official timetable shows the Locked status pill, disables draggable tiles, and blocks draft mutations.
        $officialComponent = Livewire::actingAs($fixture['registrar'])
            ->test(TermTimetable::class, ['termId' => $fixture['term']->id])
            ->assertSee('Official · Locked')
            ->assertSee('Revise schedule')
            ->assertDontSee('Draft candidate · Editable')
            ->assertDontSee('draggable="true"', false);

        $officialComponent
            ->call('moveCandidateMeeting', $lectureRow->id, 1, '10:00')
            ->assertHasErrors(['timetable'])
            ->assertNotified('Official timetable is locked');

        $officialComponent
            ->call('updateCandidateMeeting', $lectureRow->id, 1, '10:00', $fixture['roomLectureTwo']->id, $fixture['facultyTwo']->id)
            ->assertHasErrors(['timetable'])
            ->assertNotified('Official timetable is locked');

        $officialComponent
            ->call('togglePinMeeting', $lectureRow->id)
            ->assertHasErrors(['timetable'])
            ->assertNotified('Official timetable is locked');

        // Pin commitment survived publication.
        $this->assertTrue(
            SchedulingCommitment::query()
                ->where('term_id', $fixture['term']->id)
                ->where('authority_reference', 'REGISTRAR-PIN')
                ->exists(),
        );
    }

    #[Test]
    public function academic_head_is_read_only_and_cannot_mutate_draft_timetable(): void
    {
        $fixture = $this->createDraftTimetableFixture();
        $academicHead = $this->staff(User::StaffRoleAcademicHead);

        Livewire::actingAs($academicHead)
            ->test(TermTimetable::class, ['termId' => $fixture['term']->id])
            ->assertDontSee('draggable="true"', false)
            ->assertDontSee('Publish official timetable')
            ->call('moveCandidateMeeting', $fixture['lectureRow']->id, 1, '10:00')
            ->assertForbidden();

        Livewire::actingAs($academicHead)
            ->test(TermTimetable::class, ['termId' => $fixture['term']->id])
            ->call('togglePinMeeting', $fixture['lectureRow']->id)
            ->assertForbidden();
    }

    /**
     * @return array{
     *     registrar: User,
     *     term: Term,
     *     section: Section,
     *     facultyOne: User,
     *     facultyTwo: User,
     *     roomLectureOne: Room,
     *     roomLectureTwo: Room,
     *     roomLab: Room,
     *     lectureDemand: SchedulingDemand,
     *     labDemand: SchedulingDemand,
     *     run: ScheduleGenerationRun,
     *     lectureRow: CandidateScheduleRow,
     *     labRow: CandidateScheduleRow,
     * }
     */
    private function createDraftTimetableFixture(): array
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $facultyOne = $this->staff(User::StaffRoleFaculty, 'Dr. Elena Cruz');
        $facultyTwo = $this->staff(User::StaffRoleFaculty, 'Prof. Marco Reyes');

        $academicYear = AcademicYear::factory()->create();
        $term = Term::factory()->for($academicYear, 'academicYear')->create([
            'state' => Term::StateActive,
        ]);

        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
            'recorded_by' => $registrar->id,
        ]);

        foreach ([1, 2, 3, 4, 5] as $day) {
            TermTeachingGridRow::query()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
            ]);
        }

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology']);
        $curriculumVersion = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);

        $course = Course::factory()->create([
            'code' => 'IT 101',
        ]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Introduction to Computing',
            'state' => CourseSpecification::StateActive,
            'same_faculty_default' => false,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $lectureComponent = CourseComponent::factory()->for($specification, 'courseSpecification')->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1,
            'room_type_default' => Room::TypeLectureRoom,
            'same_faculty' => false,
            'sequence' => 1,
        ]);

        $secondLectureCourse = Course::factory()->create([
            'code' => 'IT 103',
        ]);
        $secondLectureSpec = CourseSpecification::factory()->for($secondLectureCourse)->create([
            'title' => 'Discrete Mathematics',
            'state' => CourseSpecification::StateActive,
            'same_faculty_default' => false,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $secondLectureComponent = CourseComponent::factory()->for($secondLectureSpec, 'courseSpecification')->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1,
            'room_type_default' => Room::TypeLectureRoom,
            'same_faculty' => false,
            'sequence' => 1,
        ]);

        $labCourse = Course::factory()->create([
            'code' => 'IT 102',
        ]);
        $labSpecification = CourseSpecification::factory()->for($labCourse)->create([
            'title' => 'Computer Programming Lab',
            'state' => CourseSpecification::StateActive,
            'same_faculty_default' => false,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $labComponent = CourseComponent::factory()->for($labSpecification, 'courseSpecification')->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => 2,
            'room_type_default' => Room::TypeComputerLaboratory,
            'same_faculty' => false,
            'sequence' => 1,
        ]);

        $curriculumEntry = CurriculumEntry::factory()
            ->for($curriculumVersion, 'curriculumVersion')
            ->for($specification, 'courseSpecification')
            ->create([
                'year_level' => 1,
                'term_type' => $term->type,
            ]);

        $secondCurriculumEntry = CurriculumEntry::factory()
            ->for($curriculumVersion, 'curriculumVersion')
            ->for($secondLectureSpec, 'courseSpecification')
            ->create([
                'year_level' => 1,
                'term_type' => $term->type,
            ]);

        $labCurriculumEntry = CurriculumEntry::factory()
            ->for($curriculumVersion, 'curriculumVersion')
            ->for($labSpecification, 'courseSpecification')
            ->create([
                'year_level' => 1,
                'term_type' => $term->type,
            ]);

        $offering = TermOffering::factory()
            ->for($term)
            ->for($curriculumEntry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => TermOffering::StateScheduled,
                'expected_count' => 35,
            ]);

        $secondOffering = TermOffering::factory()
            ->for($term)
            ->for($secondCurriculumEntry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => TermOffering::StateScheduled,
                'expected_count' => 35,
            ]);

        $labOffering = TermOffering::factory()
            ->for($term)
            ->for($labCurriculumEntry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => TermOffering::StateScheduled,
                'expected_count' => 35,
            ]);

        $section = Section::factory()
            ->for($offering, 'termOffering')
            ->create([
                'code' => 'BSIT-1A',
                'term_calendar_package_id' => $package->id,
                'course_specification_id' => $specification->id,
                'capacity' => 40,
                'state' => Section::StateOpen,
                'confirmed_at' => now(),
                'confirmed_by' => $registrar->id,
            ]);

        $secondSection = Section::factory()
            ->for($secondOffering, 'termOffering')
            ->create([
                'code' => 'BSIT-1C',
                'term_calendar_package_id' => $package->id,
                'course_specification_id' => $secondLectureSpec->id,
                'capacity' => 40,
                'state' => Section::StateOpen,
                'confirmed_at' => now(),
                'confirmed_by' => $registrar->id,
            ]);

        $labSection = Section::factory()
            ->for($labOffering, 'termOffering')
            ->create([
                'code' => 'BSIT-1B',
                'term_calendar_package_id' => $package->id,
                'course_specification_id' => $labSpecification->id,
                'capacity' => 40,
                'state' => Section::StateOpen,
                'confirmed_at' => now(),
                'confirmed_by' => $registrar->id,
            ]);

        $deliveryGroup = SectionDeliveryGroup::factory()
            ->for($section, 'section')
            ->create([
                'expected_count' => 35,
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionDeliveryGroup::StateReady,
            ]);

        $secondDeliveryGroup = SectionDeliveryGroup::factory()
            ->for($secondSection, 'section')
            ->create([
                'expected_count' => 35,
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionDeliveryGroup::StateReady,
            ]);

        $labDeliveryGroup = SectionDeliveryGroup::factory()
            ->for($labSection, 'section')
            ->create([
                'expected_count' => 35,
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionDeliveryGroup::StateReady,
            ]);

        $roomLectureOne = Room::factory()->create([
            'code' => 'RM-201',
            'name' => 'Lecture Room 201',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 45,
            'is_active' => true,
        ]);
        $roomLectureTwo = Room::factory()->create([
            'code' => 'RM-202',
            'name' => 'Lecture Room 202',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 45,
            'is_active' => true,
        ]);
        $roomLab = Room::factory()->create([
            'code' => 'LAB-301',
            'name' => 'Computer Lab 301',
            'room_type' => Room::TypeComputerLaboratory,
            'capacity' => 40,
            'is_active' => true,
        ]);

        FacultyQualification::factory()->for($facultyOne, 'faculty')->for($course)->create([
            'recorded_by' => $registrar->id,
        ]);
        FacultyQualification::factory()->for($facultyTwo, 'faculty')->for($course)->create([
            'recorded_by' => $registrar->id,
        ]);
        FacultyQualification::factory()->for($facultyOne, 'faculty')->for($secondLectureCourse)->create([
            'recorded_by' => $registrar->id,
        ]);
        FacultyQualification::factory()->for($facultyTwo, 'faculty')->for($labCourse)->create([
            'recorded_by' => $registrar->id,
        ]);

        FacultyAvailabilityDeclaration::query()->create([
            'term_id' => $term->id,
            'faculty_user_id' => $facultyOne->id,
            'version' => 1,
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'availability_mode' => FacultyAvailabilityDeclaration::ModeWindows,
            'teaching_windows' => [
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '17:00'],
                ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '12:00'],
                ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '17:00'],
            ],
            'hard_unavailability' => [],
            'declared_at' => now(),
        ]);

        FacultyAvailabilityDeclaration::query()->create([
            'term_id' => $term->id,
            'faculty_user_id' => $facultyTwo->id,
            'version' => 1,
            'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
            'availability_mode' => FacultyAvailabilityDeclaration::ModeAnyApproved,
            'teaching_windows' => [],
            'hard_unavailability' => [],
            'declared_at' => now(),
        ]);

        $lectureDemand = SchedulingDemand::factory()
            ->for($offering, 'termOffering')
            ->for($deliveryGroup, 'sectionDeliveryGroup')
            ->for($lectureComponent, 'courseComponent')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'required_duration_minutes' => 60,
                'meeting_count' => 1,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
                'source_snapshot' => [
                    'section_id' => $section->id,
                    'section_code' => $section->code,
                    'course_id' => $course->id,
                    'course_code' => $course->code,
                    'course_title' => $specification->title,
                    'same_faculty_required' => false,
                ],
            ]);

        $secondLectureDemand = SchedulingDemand::factory()
            ->for($secondOffering, 'termOffering')
            ->for($secondDeliveryGroup, 'sectionDeliveryGroup')
            ->for($secondLectureComponent, 'courseComponent')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'required_duration_minutes' => 60,
                'meeting_count' => 1,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
                'source_snapshot' => [
                    'section_id' => $secondSection->id,
                    'section_code' => $secondSection->code,
                    'course_id' => $secondLectureCourse->id,
                    'course_code' => $secondLectureCourse->code,
                    'course_title' => $secondLectureSpec->title,
                    'same_faculty_required' => false,
                ],
            ]);

        $labDemand = SchedulingDemand::factory()
            ->for($labOffering, 'termOffering')
            ->for($labDeliveryGroup, 'sectionDeliveryGroup')
            ->for($labComponent, 'courseComponent')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'required_duration_minutes' => 120,
                'meeting_count' => 1,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
                'source_snapshot' => [
                    'section_id' => $labSection->id,
                    'section_code' => $labSection->code,
                    'course_id' => $labCourse->id,
                    'course_code' => $labCourse->code,
                    'course_title' => $labSpecification->title,
                    'same_faculty_required' => false,
                ],
            ]);

        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'UnderReview',
            'input_snapshot' => [],
            'requested_by' => $registrar->id,
            'published_by' => null,
            'published_at' => null,
            'publication_version' => null,
            'diagnostics' => [
                'summary' => [
                    'assignments' => 3,
                    'conflicts' => 0,
                ],
            ],
        ]);

        app(ScheduleSolverSnapshotService::class)->captureForRun($run);
        $run->refresh();

        $lectureRow = CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $lectureDemand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $facultyOne->id,
            'room_id' => $roomLectureOne->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => ['total' => 100],
            'change_type' => 'Generated',
        ]);

        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $secondLectureDemand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $facultyOne->id,
            'room_id' => $roomLectureOne->id,
            'day_of_week' => 3,
            'starts_at' => '10:00:00',
            'ends_at' => '11:00:00',
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => ['total' => 100],
            'change_type' => 'Generated',
        ]);

        $labRow = CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $labDemand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $facultyTwo->id,
            'room_id' => $roomLab->id,
            'day_of_week' => 3,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => ['total' => 100],
            'change_type' => 'Generated',
        ]);

        return [
            'registrar' => $registrar,
            'term' => $term,
            'section' => $section,
            'facultyOne' => $facultyOne,
            'facultyTwo' => $facultyTwo,
            'roomLectureOne' => $roomLectureOne,
            'roomLectureTwo' => $roomLectureTwo,
            'roomLab' => $roomLab,
            'lectureDemand' => $lectureDemand,
            'labDemand' => $labDemand,
            'run' => $run,
            'lectureRow' => $lectureRow,
            'labRow' => $labRow,
        ];
    }

    private function staff(string $role, ?string $name = null): User
    {
        $roleRecord = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        if ($role === User::StaffRoleRegistrar) {
            $roleRecord->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'manage-schedules', 'guard_name' => 'web']));
        }

        $attributes = ['status' => User::StatusActive];
        if ($name !== null) {
            $attributes['name'] = $name;
        }

        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
