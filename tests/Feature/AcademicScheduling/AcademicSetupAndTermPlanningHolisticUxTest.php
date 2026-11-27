<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\TimetableConflictDiagnosticService;
use App\Filament\Pages\SchoolResources;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Models\AcademicYear;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use App\Support\TermPlanningStepState;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AcademicSetupAndTermPlanningHolisticUxTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_sidebar_orders_programs_curricula_subject_catalog_and_excludes_open_draft_curriculum_specs_from_subject_badge(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create(['code' => 'BSIT', 'is_active' => true]);
        $draftCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Draft 2027',
            'state' => CurriculumVersion::StateDraft,
        ]);

        // Draft specification that belongs to an open Draft curriculum must NOT inflate Subject catalog draft badge.
        $curriculumCourse = Course::factory()->create(['code' => 'IT 101']);
        $curriculumDraftSpec = CourseSpecification::factory()->for($curriculumCourse)->create([
            'title' => 'Intro to Computing',
            'state' => CourseSpecification::StateDraft,
        ]);
        CurriculumEntry::factory()->for($draftCurriculum)->for($curriculumDraftSpec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);

        $badgesBeforeStandalone = app(AcademicReadinessService::class)->sidebarBadges();
        $this->assertSame(0, $badgesBeforeStandalone['courses_with_drafts']);
        $this->assertSame(1, $badgesBeforeStandalone['curricula_pending']);

        // Standalone Draft specification outside any Draft curriculum IS counted in Subject catalog badge.
        $standaloneCourse = Course::factory()->create(['code' => 'GE 105']);
        CourseSpecification::factory()->for($standaloneCourse)->create([
            'title' => 'Standalone Draft Subject',
            'state' => CourseSpecification::StateDraft,
        ]);

        $badgesAfterStandalone = app(AcademicReadinessService::class)->sidebarBadges();
        $this->assertSame(1, $badgesAfterStandalone['courses_with_drafts']);
        $this->assertSame(1, $badgesAfterStandalone['curricula_pending']);

        $items = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems());
        $programsCurriculaItem = $items->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Programs & Curricula');
        $this->assertNotNull($programsCurriculaItem);

        $children = collect($programsCurriculaItem->getChildItems())->values();
        $this->assertSame(
            ['Programs', 'Curricula', 'Subject catalog'],
            $children->map->getLabel()->all(),
        );
        $this->assertSame('1', $children[1]->getBadge());
        $this->assertSame('1', $children[2]->getBadge());
    }

    public function test_review_curriculum_version_publish_modal_requires_starting_batch_or_acknowledgement_and_attaches_batch_transactionally(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create(['code' => 'BSCS', 'is_active' => true, 'duration_years' => 4]);
        $academicYear = AcademicYear::factory()->create(['label' => '2028-2029']);

        $draftWithBatch = $this->createReadyDraftCurriculum($program, 'BSCS 2028 Plan', 'CS 101');
        $draftWithoutBatch = $this->createReadyDraftCurriculum($program, 'BSCS Unbatched Plan', 'CS 102');

        // Without selecting a starting batch or checking publish_without_batch, publish is blocked cleanly.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftWithBatch->getRouteKey()])
            ->callAction('publishCurriculum', data: [
                'starting_academic_year_id' => null,
                'publish_without_batch' => false,
            ])
            ->assertHasActionErrors(['starting_academic_year_id']);

        $this->assertSame(CurriculumVersion::StateDraft, $draftWithBatch->fresh()->state);
        $this->assertSame(0, CurriculumVersionBatch::query()->where('curriculum_version_id', $draftWithBatch->id)->count());

        // Selecting a Starting Batch attaches the batch and activates the curriculum transactionally.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftWithBatch->getRouteKey()])
            ->mountAction('publishCurriculum')
            ->assertMountedActionModalSee('Starting batch (Academic Year)')
            ->assertMountedActionModalSee('Publish without a batch for now')
            ->setActionData([
                'starting_academic_year_id' => $academicYear->id,
                'publish_without_batch' => false,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Curriculum published');

        $this->assertSame(CurriculumVersion::StateActive, $draftWithBatch->fresh()->state);
        $this->assertDatabaseHas('curriculum_version_batches', [
            'curriculum_version_id' => $draftWithBatch->id,
            'program_id' => $program->id,
            'academic_year_id' => $academicYear->id,
        ]);

        // Explicit publish_without_batch acknowledgement activates the curriculum without attaching any batch.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftWithoutBatch->getRouteKey()])
            ->callAction('publishCurriculum', data: [
                'starting_academic_year_id' => null,
                'publish_without_batch' => true,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Curriculum published');

        $this->assertSame(CurriculumVersion::StateActive, $draftWithoutBatch->fresh()->state);
        $this->assertSame(0, CurriculumVersionBatch::query()->where('curriculum_version_id', $draftWithoutBatch->id)->count());
    }

    public function test_term_planning_step_state_enforces_sequential_hard_gating_for_registrar_and_never_locks_out_academic_head(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateDraft, 'type' => Term::TypeFirstSemester]);

        // Even with active rooms and faculty in the school, Step 3 must NEVER be falsely marked done when 0 classes exist.
        Room::factory()->create(['code' => 'RM-101', 'room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);
        $faculty = User::factory()->create([...User::staffNamePayload('Clara', null, 'Reyes'), 'status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);

        // Stage 0: No active calendar -> Steps 2, 3, 4 locked behind Step 1; resources_done is false.
        $stateBeforeCalendar = TermPlanningStepState::evaluate($term, readOnly: false);
        $this->assertFalse($stateBeforeCalendar['calendar_done']);
        $this->assertFalse($stateBeforeCalendar['classes_done']);
        $this->assertFalse($stateBeforeCalendar['resources_done']);
        $this->assertTrue($stateBeforeCalendar['steps']['calendar']['unlocked']);
        $this->assertTrue($stateBeforeCalendar['steps']['classes']['locked']);
        $this->assertSame(1, $stateBeforeCalendar['steps']['classes']['required_step_number']);
        $this->assertTrue($stateBeforeCalendar['steps']['resources']['locked']);
        $this->assertSame(1, $stateBeforeCalendar['steps']['resources']['required_step_number']);
        $this->assertTrue($stateBeforeCalendar['steps']['timetable']['locked']);
        $this->assertSame(1, $stateBeforeCalendar['steps']['timetable']['required_step_number']);

        // Direct URLs render the prerequisite gate card for Registrar.
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertSee('Complete Step 1 first')
            ->assertSee('Go to 1. Calendar');
        Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertSee('Complete Step 1 first')
            ->assertSee('Go to 1. Calendar');
        Livewire::withQueryParams(['termId' => $term->id])->test(TermTimetable::class)
            ->assertSee('Complete Step 1 first')
            ->assertSee('Go to 1. Calendar');

        // Stage 1: Activate calendar with 0 classes -> Step 2 unlocks; Steps 3 & 4 locked behind Step 2; Step 3 NOT done.
        $package = $this->createActiveCalendarPackage($term);
        $stateAfterCalendar = TermPlanningStepState::evaluate($term->fresh(), readOnly: false);
        $this->assertTrue($stateAfterCalendar['calendar_done']);
        $this->assertFalse($stateAfterCalendar['classes_done']);
        $this->assertFalse($stateAfterCalendar['resources_done']);
        $this->assertTrue($stateAfterCalendar['steps']['classes']['unlocked']);
        $this->assertTrue($stateAfterCalendar['steps']['resources']['locked']);
        $this->assertSame(2, $stateAfterCalendar['steps']['resources']['required_step_number']);
        $this->assertTrue($stateAfterCalendar['steps']['timetable']['locked']);
        $this->assertSame(2, $stateAfterCalendar['steps']['timetable']['required_step_number']);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertSee('Complete Step 2 first')
            ->assertSee('Go to 2. Sections & classes');
        Livewire::withQueryParams(['termId' => $term->id])->test(TermTimetable::class)
            ->assertSee('Complete Step 2 first')
            ->assertSee('Go to 2. Sections & classes');

        // Stage 2: Add student section + confirmed class without a qualified teacher -> Step 3 unlocks; Step 4 locked behind Step 3.
        $course = $this->createConfirmedClassInTerm($term, $package, 'CS 201', 'BSCS-2A');
        $stateAfterClass = TermPlanningStepState::evaluate($term->fresh(), readOnly: false);
        $this->assertTrue($stateAfterClass['has_confirmed_class']);
        $this->assertTrue($stateAfterClass['steps']['resources']['unlocked']);
        $this->assertTrue($stateAfterClass['steps']['timetable']['locked']);
        $this->assertSame(3, $stateAfterClass['steps']['timetable']['required_step_number']);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermTimetable::class)
            ->assertSee('Complete Step 3 first')
            ->assertSee('Go to 3. Faculty & rooms');

        // Stage 3: Qualify active faculty for the offered subject -> Step 4 unlocks.
        FacultyQualification::factory()->create([
            'faculty_user_id' => $faculty->id,
            'course_id' => $course->id,
            'is_active' => true,
            'recorded_by' => $registrar->id,
        ]);
        $stateAfterQualification = TermPlanningStepState::evaluate($term->fresh(), readOnly: false);
        $this->assertTrue($stateAfterQualification['steps']['timetable']['unlocked']);
        $this->assertFalse($stateAfterQualification['steps']['timetable']['locked']);

        // Academic Head ($readOnly = true) is NEVER locked out even on a brand-new draft term with no calendar or classes.
        $emptyTerm = Term::factory()->create(['state' => Term::StateDraft]);
        $headState = TermPlanningStepState::evaluate($emptyTerm, readOnly: true);
        foreach (['calendar', 'classes', 'resources', 'timetable'] as $stepKey) {
            $this->assertTrue($headState['steps'][$stepKey]['unlocked']);
            $this->assertFalse($headState['steps'][$stepKey]['locked']);
        }

        $this->staff(User::StaffRoleAcademicHead);
        foreach ([TermClasses::class, TermResources::class, TermTimetable::class] as $pageClass) {
            Livewire::withQueryParams(['termId' => $emptyTerm->id])->test($pageClass)
                ->assertDontSee('Complete Step 1 first')
                ->assertDontSee('Complete Step 2 first')
                ->assertDontSee('Complete Step 3 first');
        }
    }

    public function test_calendar_core_signature_changes_on_daily_break_edits_and_activation_marks_active_draft_runs_stale(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $year = AcademicYear::factory()->create([
            'starts_on' => '2027-08-01',
            'ends_on' => '2028-05-31',
        ]);
        $term = Term::factory()->for($year)->create([
            'state' => Term::StateActive,
            'starts_on' => '2027-08-10',
            'ends_on' => '2027-12-15',
        ]);

        $activePackage = TermCalendarPackage::factory()->for($term)->create([
            'version' => 1,
            'state' => TermCalendarPackage::StateActive,
            'administrative_starts_on' => '2027-08-10',
            'administrative_ends_on' => '2027-12-15',
            'classes_start_on' => '2027-08-10',
            'classes_end_on' => '2027-12-15',
            'activated_at' => now(),
        ]);
        TermTeachingGridRow::factory()->for($activePackage, 'package')->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);
        $originalSignature = $activePackage->fresh(['teachingGridRows', 'datedExceptions'])->coreSignature();

        $draftRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'Draft',
            'diagnostics' => [],
        ]);

        // Draft v2 keeps identical dates/hours but adds a daily lunch break -> coreSignature MUST change.
        $draftPackage = TermCalendarPackage::factory()->for($term)->create([
            'version' => 2,
            'state' => TermCalendarPackage::StateDraft,
            'administrative_starts_on' => '2027-08-10',
            'administrative_ends_on' => '2027-12-15',
            'classes_start_on' => '2027-08-10',
            'classes_end_on' => '2027-12-15',
        ]);
        $draftGridRow = TermTeachingGridRow::factory()->for($draftPackage, 'package')->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [
                ['starts_at' => '12:00', 'ends_at' => '13:00'],
            ],
        ]);
        $signatureWithBreak = $draftPackage->fresh(['teachingGridRows', 'datedExceptions'])->coreSignature();
        $this->assertNotSame($originalSignature, $signatureWithBreak);

        // Editing the break window changes coreSignature again.
        $draftGridRow->update([
            'breaks' => [
                ['starts_at' => '12:00', 'ends_at' => '13:30'],
            ],
        ]);
        $signatureEditedBreak = $draftPackage->fresh(['teachingGridRows', 'datedExceptions'])->coreSignature();
        $this->assertNotSame($signatureWithBreak, $signatureEditedBreak);

        // Activating the revised package marks existing active draft runs as Stale with clear reason.
        $activator = app(ActivateTermCalendarPackage::class);
        $activator->execute($draftPackage->fresh(), $registrar);

        $this->assertSame(1, $activator->lastStaleDraftRunCount());
        $freshRun = $draftRun->fresh();
        $this->assertSame('Stale', $freshRun->candidate_state);
        $this->assertStringContainsString('daily breaks', (string) $freshRun->candidate_review_reason);
        $this->assertSame($freshRun->candidate_review_reason, data_get($freshRun->diagnostics, 'stale_reason'));
    }

    public function test_term_classes_defaults_to_student_sections_first_and_shows_prerequisite_guidance_when_no_sections_exist(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $this->createActiveCalendarPackage($term);

        $component = Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertSet('classesView', 'cohorts');

        $html = $component->html();
        $posSections = strpos($html, 'classesView=cohorts');
        $posClasses = strpos($html, 'classesView=classes');
        $this->assertNotFalse($posSections);
        $this->assertNotFalse($posClasses);
        $this->assertTrue($posSections < $posClasses);

        // Switching to Classes with 0 student sections shows clear prerequisite guidance and CTA.
        $component->call('chooseClassesView', 'classes')
            ->assertSet('classesView', 'classes')
            ->assertSee('Add a student section first')
            ->assertSee('Add student section');
    }

    public function test_term_resources_falls_back_when_term_faculty_is_empty_supports_inline_room_crud_and_school_resources_defaults_to_faculty(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive, 'type' => Term::TypeFirstSemester]);
        $package = $this->createActiveCalendarPackage($term);
        $this->createConfirmedClassInTerm($term, $package, 'IT 301', 'BSIT-3A');

        // Active school faculty exists, but has 0 qualifications for this term's offered subjects (termFacultyCount = 0).
        $unassignedFaculty = User::factory()->create([
            ...User::staffNamePayload('Mateo', null, 'Cruz'),
            'status' => User::StatusActive,
        ]);
        $unassignedFaculty->assignRole(User::StaffRoleFaculty);

        // Step 3 Faculty tab auto-falls back instead of showing a silently empty table.
        Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'faculty'])->test(TermResources::class)
            ->assertSee('Mateo Cruz')
            ->assertSee('Needs subject assignment');

        // Step 3 Rooms tab exposes Add room and Edit room inline without TypeError.
        $existingRoom = Room::factory()->create([
            'code' => 'LAB-101',
            'name' => 'Computer Lab 1',
            'room_type' => Room::TypeComputerLaboratory,
            'capacity' => 30,
            'is_active' => true,
        ]);

        Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'rooms'])->test(TermResources::class)
            ->assertActionVisible(TestAction::make('addRoom')->table())
            ->callAction(TestAction::make('addRoom')->table(), data: [
                'code' => 'rm-205',
                'name' => 'Lecture Hall 205',
                'building' => 'Main Building',
                'room_type' => Room::TypeLectureRoom,
                'capacity' => 45,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('rooms', [
            'code' => 'RM-205',
            'name' => 'Lecture Hall 205',
            'capacity' => 45,
        ]);

        Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'rooms'])->test(TermResources::class)
            ->assertTableActionVisible('editRoom', $existingRoom)
            ->callTableAction('editRoom', $existingRoom, data: [
                'code' => 'LAB-101',
                'name' => 'Computer Lab 1 Updated',
                'building' => 'Tech Wing',
                'room_type' => Room::TypeComputerLaboratory,
                'capacity' => 36,
                'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Computer Lab 1 Updated', $existingRoom->fresh()->name);
        $this->assertSame(36, (int) $existingRoom->fresh()->capacity);

        // SchoolResources defaults to faculty tab (Faculty | Rooms).
        $schoolResources = Livewire::test(SchoolResources::class)
            ->assertSet('tab', 'faculty');
        $srHtml = $schoolResources->html();
        $posFaculty = strpos($srHtml, 'Faculty');
        $posRooms = strpos($srHtml, 'Rooms');
        $this->assertNotFalse($posFaculty);
        $this->assertNotFalse($posRooms);
        $this->assertTrue($posFaculty < $posRooms);
    }

    public function test_timetable_conflict_diagnostic_service_reports_human_readable_causes_and_step_links(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create([
            'state' => Term::StateActive,
            'default_max_units' => 6,
        ]);
        $activePackage = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        TermTeachingGridRow::factory()->for($activePackage, 'package')->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [
                ['starts_at' => '12:00', 'ends_at' => '13:00'],
            ],
        ]);

        $teacher = User::factory()->create([...User::staffNamePayload('Elena', null, 'Santos'), 'status' => User::StatusActive]);
        $teacher->assignRole(User::StaffRoleFaculty);
        $program = Program::factory()->create(['code' => 'BSCS']);
        $cohort = TermCohort::factory()->for($term)->for($program)->create([
            'reference' => 'BSCS-1A',
            'confirmed_count' => 50,
            'confirmed_at' => now(),
        ]);
        $smallLectureRoom = Room::factory()->create([
            'code' => 'RM-101',
            'name' => 'Small Lecture Room',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 30,
            'is_active' => true,
        ]);

        // Scenario A: Infeasible run with faculty load overload, fixed-slot teacher/section collision, and room shortage.
        $infeasibleRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusFailed,
            'input_snapshot' => [
                'term' => [
                    'default_max_units' => 6,
                    'teaching_grid_rows' => [
                        ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00'],
                    ],
                ],
                'faculty' => [
                    ['faculty_id' => $teacher->id, 'max_allowed_units' => 6],
                ],
                'faculty_availability' => [
                    ['faculty_user_id' => $teacher->id, 'availability_mode' => FacultyAvailabilityDeclaration::ModeAnyApproved],
                ],
                'rooms' => [
                    [
                        'room_id' => $smallLectureRoom->id,
                        'code' => 'RM-101',
                        'name' => 'Small Lecture Room',
                        'room_type' => Room::TypeLectureRoom,
                        'capacity' => 30,
                        'feature_keys' => [],
                    ],
                ],
                'calendar_blocks' => [],
                'scheduling_demands' => [
                    [
                        'scheduling_demand_id' => 9001,
                        'term_offering_id' => 701,
                        'section_delivery_group_id' => 801,
                        'course_code' => 'CS 101',
                        'component_type' => CourseComponent::TypeLecture,
                        'load_units' => 4,
                        'meeting_count' => 1,
                        'required_duration_minutes' => 90,
                        'modality' => TermOffering::ModalityFaceToFace,
                        'room_required' => true,
                        'room_type_requirement' => CourseComponent::RoomTypeLectureRoom,
                        'expected_count' => 25,
                        'eligible_faculty_user_ids' => [$teacher->id],
                        'fixed_faculty_user_id' => $teacher->id,
                        'fixed_day_of_week' => 1,
                        'fixed_start_time' => '09:00',
                        'cohort_or_student_group_ids' => [$cohort->id],
                    ],
                    [
                        'scheduling_demand_id' => 9002,
                        'term_offering_id' => 702,
                        'section_delivery_group_id' => 802,
                        'course_code' => 'CS 102',
                        'component_type' => CourseComponent::TypeLaboratory,
                        'load_units' => 4,
                        'meeting_count' => 1,
                        'required_duration_minutes' => 90,
                        'modality' => TermOffering::ModalityFaceToFace,
                        'room_required' => true,
                        'room_type_requirement' => CourseComponent::RoomTypeLaboratory,
                        'expected_count' => 50,
                        'eligible_faculty_user_ids' => [$teacher->id],
                        'fixed_faculty_user_id' => $teacher->id,
                        'fixed_day_of_week' => 1,
                        'fixed_start_time' => '09:30',
                        'cohort_or_student_group_ids' => [$cohort->id],
                    ],
                ],
            ],
            'diagnostics' => [
                'solver_result' => [
                    'status' => 'Infeasible',
                    'findings' => [],
                ],
            ],
        ]);

        $service = app(TimetableConflictDiagnosticService::class);
        $diagnosis = $service->diagnose($infeasibleRun, $term);

        $this->assertTrue($diagnosis['has_conflicts']);
        $items = collect($diagnosis['items']);

        $loadItem = $items->firstWhere('category', 'Faculty load');
        $this->assertNotNull($loadItem);
        $this->assertStringContainsString('Elena Santos', $loadItem['title']);
        $this->assertSame(TermResources::getUrl(['termId' => $term->id, 'resourceView' => 'faculty']), $loadItem['action_url']);

        $fixedClashItem = $items->firstWhere('category', 'Fixed schedule clash');
        $this->assertNotNull($fixedClashItem);
        $this->assertStringContainsString('Elena Santos', $fixedClashItem['title']);
        $this->assertStringContainsString('CS 101', $fixedClashItem['why']);
        $this->assertStringContainsString('CS 102', $fixedClashItem['why']);
        $this->assertSame(TermClasses::getUrl(['termId' => $term->id, 'classesView' => 'classes']), $fixedClashItem['action_url']);

        $roomItem = $items->firstWhere('category', 'Room shortage');
        $this->assertNotNull($roomItem);
        $this->assertStringContainsString('CS 102', $roomItem['title']);
        $this->assertSame(TermResources::getUrl(['termId' => $term->id, 'resourceView' => 'rooms']), $roomItem['action_url']);

        // No raw internal scheduling_demand # IDs leak anywhere in the human-readable diagnosis.
        $serialized = (string) json_encode($diagnosis);
        $this->assertStringNotContainsString('scheduling_demand #', $serialized);
        $this->assertStringNotContainsString('9001', $serialized);
        $this->assertStringNotContainsString('9002', $serialized);

        // Scenario B: Stale draft run whose candidate meeting overlaps the updated daily break (12:00-13:00).
        $course = $this->createConfirmedClassInTerm($term, $activePackage, 'CS 105', 'BSCS-1B');
        $demand = SchedulingDemand::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
            ->firstOrFail();

        $staleRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'Stale',
            'input_snapshot' => [
                'scheduling_demands' => [
                    [
                        'scheduling_demand_id' => $demand->id,
                        'course_code' => $course->code,
                        'component_type' => CourseComponent::TypeLecture,
                        'cohort_or_student_group_ids' => [$cohort->id],
                    ],
                ],
            ],
            'diagnostics' => [
                'stale_reason' => 'Marked stale because the active term calendar daily breaks changed.',
            ],
        ]);
        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $staleRun->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $teacher->id,
            'room_id' => $smallLectureRoom->id,
            'day_of_week' => 1,
            'starts_at' => '11:30:00',
            'ends_at' => '13:00:00',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        $staleDiagnosis = $service->diagnose($staleRun, $term);
        $this->assertTrue($staleDiagnosis['has_conflicts']);
        $breakItem = collect($staleDiagnosis['items'])->firstWhere('category', 'Calendar break overlap');
        $this->assertNotNull($breakItem);
        $this->assertStringContainsString('daily break', $breakItem['title']);
        $this->assertSame(TermCalendar::getUrl(['termId' => $term->id]), $breakItem['action_url']);
        $this->assertStringNotContainsString('scheduling_demand #', (string) json_encode($staleDiagnosis));
    }

    public function test_timetable_conflict_diagnostic_service_detects_break_split_contiguous_block_and_distinct_day_shortages(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create([
            'state' => Term::StateActive,
            'default_max_units' => 18,
        ]);
        $teacher = User::factory()->create([
            ...User::staffNamePayload('Marco', null, 'Villanueva'),
            'status' => User::StatusActive,
        ]);
        $teacher->assignRole(User::StaffRoleFaculty);
        $program = Program::factory()->create(['code' => 'BSIT']);
        $cohort = TermCohort::factory()->for($term)->for($program)->create([
            'reference' => 'BSIT-2A',
            'confirmed_count' => 30,
            'confirmed_at' => now(),
        ]);
        $labRoom = Room::factory()->create([
            'code' => 'LAB-201',
            'name' => 'Science Lab 201',
            'room_type' => Room::TypeLaboratory,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $lectureRoom = Room::factory()->create([
            'code' => 'RM-201',
            'name' => 'Lecture Room 201',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);

        // Case A: 3-hour (180 min) LAB demand where sole teacher declared 09:30-15:00 on Monday.
        // After the 12:00-13:00 lunch break, total open time is 4.5 hrs (> 3 hrs),
        // yet the longest uninterrupted block is 2 hrs 30 min (< 3 hrs).
        $breakSplitRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusFailed,
            'input_snapshot' => [
                'term' => [
                    'default_max_units' => 18,
                    'teaching_grid_rows' => [
                        ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00'],
                    ],
                ],
                'faculty' => [
                    ['faculty_id' => $teacher->id, 'max_allowed_units' => 18],
                ],
                'faculty_availability' => [
                    [
                        'faculty_user_id' => $teacher->id,
                        'availability_mode' => FacultyAvailabilityDeclaration::ModeWindows,
                        'teaching_windows' => [
                            ['day_of_week' => 1, 'start_time' => '09:30', 'end_time' => '15:00'],
                        ],
                        'hard_unavailability' => [],
                    ],
                ],
                'rooms' => [
                    [
                        'room_id' => $labRoom->id,
                        'code' => 'LAB-201',
                        'name' => 'Science Lab 201',
                        'room_type' => Room::TypeLaboratory,
                        'capacity' => 40,
                        'feature_keys' => [],
                    ],
                ],
                'calendar_blocks' => [
                    [
                        'event_type' => 'TeachingGridBreak',
                        'scope_type' => 'Institution',
                        'room_id' => null,
                        'faculty_user_id' => null,
                        'day_of_week' => 1,
                        'starts_at' => '12:00:00',
                        'ends_at' => '13:00:00',
                    ],
                ],
                'scheduling_demands' => [
                    [
                        'scheduling_demand_id' => 9101,
                        'term_offering_id' => 711,
                        'section_delivery_group_id' => 811,
                        'course_code' => 'IT 204',
                        'component_type' => CourseComponent::TypeLaboratory,
                        'load_units' => 3,
                        'meeting_count' => 1,
                        'required_duration_minutes' => 180,
                        'modality' => TermOffering::ModalityFaceToFace,
                        'room_required' => true,
                        'room_type_requirement' => CourseComponent::RoomTypeLaboratory,
                        'expected_count' => 30,
                        'eligible_faculty_user_ids' => [$teacher->id],
                        'cohort_or_student_group_ids' => [$cohort->id],
                    ],
                ],
            ],
            'diagnostics' => [
                'solver_result' => [
                    'status' => 'Infeasible',
                    'findings' => [],
                ],
            ],
        ]);

        $service = app(TimetableConflictDiagnosticService::class);
        $breakSplitDiagnosis = $service->diagnose($breakSplitRun, $term);

        $this->assertTrue($breakSplitDiagnosis['has_conflicts']);
        $this->assertCount(1, $breakSplitDiagnosis['items']);

        $contiguousItem = $breakSplitDiagnosis['items'][0];
        $this->assertSame('Calendar break overlap', $contiguousItem['category']);
        $this->assertSame('danger', $contiguousItem['severity']);
        $this->assertStringContainsString('IT 204', $contiguousItem['title']);
        $this->assertStringContainsString('BSIT-2A', $contiguousItem['title']);
        $this->assertStringContainsString('3 hrs', $contiguousItem['title']);
        $this->assertStringContainsString('uninterrupted hours', $contiguousItem['title']);
        $this->assertSame('Open 3. Faculty & rooms', $contiguousItem['action_label']);
        $this->assertSame(
            TermResources::getUrl(['termId' => $term->id, 'resourceView' => 'faculty']),
            $contiguousItem['action_url'],
        );

        $contiguousInvolved = implode(' | ', $contiguousItem['involved']);
        $this->assertStringContainsString('Marco Villanueva', $contiguousInvolved);
        $this->assertStringContainsString('2 hrs 30 min', $contiguousInvolved);
        $this->assertStringContainsString('Daily break:', $contiguousInvolved);
        $this->assertStringNotContainsString('9101', (string) json_encode($breakSplitDiagnosis));

        // Case B: 2-meeting/week (2x90 min) demand where the sole teacher has 10 hrs total open,
        // but Tuesday's 11:00-14:00 window is split by the 12:00-13:00 break into two 1-hour blocks,
        // leaving only 1 distinct usable day for 1 hr 30 min meetings.
        $distinctDayRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusFailed,
            'input_snapshot' => [
                'term' => [
                    'default_max_units' => 18,
                    'teaching_grid_rows' => [
                        ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00'],
                        ['day_of_week' => 2, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00'],
                    ],
                ],
                'faculty' => [
                    ['faculty_id' => $teacher->id, 'max_allowed_units' => 18],
                ],
                'faculty_availability' => [
                    [
                        'faculty_user_id' => $teacher->id,
                        'availability_mode' => FacultyAvailabilityDeclaration::ModeWindows,
                        'teaching_windows' => [
                            ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '17:00'],
                            ['day_of_week' => 2, 'start_time' => '11:00', 'end_time' => '14:00'],
                        ],
                        'hard_unavailability' => [],
                    ],
                ],
                'rooms' => [
                    [
                        'room_id' => $lectureRoom->id,
                        'code' => 'RM-201',
                        'name' => 'Lecture Room 201',
                        'room_type' => Room::TypeLectureRoom,
                        'capacity' => 40,
                        'feature_keys' => [],
                    ],
                ],
                'calendar_blocks' => [
                    [
                        'event_type' => 'TeachingGridBreak',
                        'scope_type' => 'Institution',
                        'room_id' => null,
                        'faculty_user_id' => null,
                        'day_of_week' => 1,
                        'starts_at' => '12:00:00',
                        'ends_at' => '13:00:00',
                    ],
                    [
                        'event_type' => 'TeachingGridBreak',
                        'scope_type' => 'Institution',
                        'room_id' => null,
                        'faculty_user_id' => null,
                        'day_of_week' => 2,
                        'starts_at' => '12:00:00',
                        'ends_at' => '13:00:00',
                    ],
                ],
                'scheduling_demands' => [
                    [
                        'scheduling_demand_id' => 9102,
                        'term_offering_id' => 712,
                        'section_delivery_group_id' => 812,
                        'course_code' => 'IT 205',
                        'component_type' => CourseComponent::TypeLecture,
                        'load_units' => 3,
                        'meeting_count' => 2,
                        'required_duration_minutes' => 90,
                        'modality' => TermOffering::ModalityFaceToFace,
                        'room_required' => true,
                        'room_type_requirement' => CourseComponent::RoomTypeLectureRoom,
                        'expected_count' => 30,
                        'eligible_faculty_user_ids' => [$teacher->id],
                        'cohort_or_student_group_ids' => [$cohort->id],
                    ],
                ],
            ],
            'diagnostics' => [
                'solver_result' => [
                    'status' => 'Infeasible',
                    'findings' => [],
                ],
            ],
        ]);

        $distinctDayDiagnosis = $service->diagnose($distinctDayRun, $term);

        $this->assertTrue($distinctDayDiagnosis['has_conflicts']);
        $this->assertCount(1, $distinctDayDiagnosis['items']);

        $dayItem = $distinctDayDiagnosis['items'][0];
        $this->assertSame('Teacher availability', $dayItem['category']);
        $this->assertSame('danger', $dayItem['severity']);
        $this->assertStringContainsString('IT 205', $dayItem['title']);
        $this->assertStringContainsString('2 separate days', $dayItem['title']);
        $this->assertSame('Open 3. Faculty & rooms', $dayItem['action_label']);
        $this->assertSame(
            TermResources::getUrl(['termId' => $term->id, 'resourceView' => 'faculty']),
            $dayItem['action_url'],
        );

        $dayInvolved = implode(' | ', $dayItem['involved']);
        $this->assertStringContainsString('2 distinct days needed', $dayInvolved);
        $this->assertStringContainsString('Marco Villanueva (1 usable day for 1 hr 30 min meetings)', $dayInvolved);
        $this->assertStringNotContainsString('9102', (string) json_encode($distinctDayDiagnosis));
    }

    public function test_step2_auto_naming_prevents_bsiu_and_autofills_program_year_references(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create([
            'state' => Term::StateActive,
            'type' => Term::TypeSecondSemester,
        ]);
        $this->createActiveCalendarPackage($term);

        $program = Program::factory()->create([
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
            'duration_years' => 5,
            'is_active' => true,
        ]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT 101', 'state' => Course::StateActive]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Intro to Computing',
            'credit_units' => 3,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);
        CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);

        // Auto-fill updates cleanly across Year 1 -> Year 2 -> Year 5 and shows inline gap warning for Second Semester.
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->mountAction('recordCohort')
            ->set('mountedActions.0.data.curriculum_version_id', $curriculum->id)
            ->assertSet('mountedActions.0.data.reference', 'BSIT 1-1')
            ->assertMountedActionModalSee('has 0 subjects configured for Second Semester.')
            ->assertMountedActionModalSee('Subjects are currently configured for: First Semester (Year 1).')
            ->assertMountedActionModalSee('Open curriculum')
            ->set('mountedActions.0.data.year_level', 2)
            ->assertSet('mountedActions.0.data.reference', 'BSIT 2-1')
            ->set('mountedActions.0.data.year_level', 5)
            ->assertSet('mountedActions.0.data.reference', 'BSIT 5-1')
            ->set('mountedActions.0.data.year_level', 1)
            ->set('mountedActions.0.data.reference', 'BSIT')
            ->set('mountedActions.0.data.section_count', 2)
            ->assertMountedActionModalSee('Will create: BSIT 1-1, BSIT 1-2')
            ->set('mountedActions.0.data.confirmed_count', 35)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('term_cohorts', ['term_id' => $term->id, 'reference' => 'BSIT 1-1', 'year_level' => 1]);
        $this->assertDatabaseHas('term_cohorts', ['term_id' => $term->id, 'reference' => 'BSIT 1-2', 'year_level' => 1]);
        $this->assertDatabaseMissing('term_cohorts', ['term_id' => $term->id, 'reference' => 'BSIU']);

        // Letters-only base "BSIT" with Year 5 and count=2 creates BSIT 5-1, BSIT 5-2 (never BSIU).
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->callAction('recordCohort', [
                'curriculum_version_id' => $curriculum->id,
                'year_level' => 5,
                'reference' => 'BSIT',
                'confirmed_count' => 30,
                'section_count' => 2,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('term_cohorts', ['term_id' => $term->id, 'reference' => 'BSIT 5-1', 'year_level' => 5]);
        $this->assertDatabaseHas('term_cohorts', ['term_id' => $term->id, 'reference' => 'BSIT 5-2', 'year_level' => 5]);
        $this->assertDatabaseMissing('term_cohorts', ['term_id' => $term->id, 'reference' => 'BSIU']);

        // Next auto-suggested Year 1 reference skips existing BSIT 1-1 and BSIT 1-2 to BSIT 1-3.
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->mountAction('recordCohort')
            ->set('mountedActions.0.data.curriculum_version_id', $curriculum->id)
            ->assertSet('mountedActions.0.data.reference', 'BSIT 1-3');

        // Trailing letter after digit ("BSIT 1A") still increments cleanly to BSIT 1A, BSIT 1B.
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->callAction('recordCohort', [
                'curriculum_version_id' => $curriculum->id,
                'year_level' => 1,
                'reference' => 'BSIT 1A',
                'confirmed_count' => 28,
                'section_count' => 2,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('term_cohorts', ['term_id' => $term->id, 'reference' => 'BSIT 1A', 'year_level' => 1]);
        $this->assertDatabaseHas('term_cohorts', ['term_id' => $term->id, 'reference' => 'BSIT 1B', 'year_level' => 1]);
    }

    public function test_step2_surfaces_upfront_curriculum_readiness_and_open_curriculum_recovery_for_empty_semester(): void
    {
        $this->staff(User::StaffRoleRegistrar);
        $academicYear = AcademicYear::factory()->create(['label' => '2026-2027']);
        $term = Term::factory()->for($academicYear)->create([
            'state' => Term::StateActive,
            'type' => Term::TypeSecondSemester,
            'label' => 'Second Semester AY 2026-2027',
        ]);
        $this->createActiveCalendarPackage($term);

        $bsit = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);
        $bsitCurriculum = CurriculumVersion::factory()->for($bsit)->create(['state' => CurriculumVersion::StateActive]);
        $bsitCurriculum->academicYears()->attach($academicYear->id, ['program_id' => $bsit->id]);

        $itCourse = Course::factory()->create(['code' => 'IT 101', 'state' => Course::StateActive]);
        $itSpec = CourseSpecification::factory()->for($itCourse)->create([
            'title' => 'Intro to Computing',
            'credit_units' => 3,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($itSpec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);
        CurriculumEntry::factory()->for($bsitCurriculum)->for($itSpec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);

        $expectedCurriculumUrl = CurriculumVersionResource::getUrl('review', ['record' => $bsitCurriculum->id]);

        // Upfront readiness banner names the empty semester (Second Semester) and configured semester (First Semester).
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->assertSee('Second Semester (2026-2027) schedules all running year levels together')
            ->assertSee('Curriculum readiness for Second Semester')
            ->assertSee('has 0 subjects for Second Semester')
            ->assertSee('configured: First Semester')
            ->assertSeeHtml('href="'.e($expectedCurriculumUrl).'"');

        $emptyCohort = TermCohort::factory()->for($term)->for($bsit)->create([
            'curriculum_version_id' => $bsitCurriculum->id,
            'year_level' => 1,
            'reference' => 'BSIT 1-1',
            'confirmed_count' => 35,
            'confirmed_at' => now(),
        ]);

        // Row hides dead-end Load subjects button and shows 0 subjects copy + Open curriculum & Add class recovery.
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->assertSee('0 Second Semester subjects in curriculum')
            ->assertSee('No Second Semester curriculum subjects are configured for these sections yet.')
            ->assertSee('Open Curricula')
            ->assertSee('Classes tab')
            ->assertDontSee('Review and load curriculum classes next.')
            ->assertTableActionHidden('loadCohortCurriculum', $emptyCohort)
            ->assertTableActionVisible('openCohortCurriculum', $emptyCohort)
            ->assertTableActionHasUrl('openCohortCurriculum', $expectedCurriculumUrl, $emptyCohort)
            ->assertTableActionVisible('addCohortClass', $emptyCohort);

        // Load classes from curriculum modal explains the gap and provides Open curriculum + Add class recovery.
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'classes'])
            ->test(TermClasses::class)
            ->mountAction('loadCurriculumSubjects', ['cohortId' => $emptyCohort->id])
            ->assertMountedActionModalSee('has 0 subjects for Second Semester.')
            ->assertMountedActionModalSee('Subjects currently exist for: First Semester (Year 1).')
            ->assertMountedActionModalSee('Open curriculum')
            ->assertMountedActionModalSee('Add class')
            ->assertSeeHtml('href="'.e($expectedCurriculumUrl).'"');

        // A cohort whose curriculum DOES have Second Semester subjects keeps Load subjects visible and opens the modal cleanly.
        $bscs = Program::factory()->create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'is_active' => true]);
        $bscsCurriculum = CurriculumVersion::factory()->for($bscs)->create(['state' => CurriculumVersion::StateActive]);
        $csCourse = Course::factory()->create(['code' => 'CS 102', 'state' => Course::StateActive]);
        $csSpec = CourseSpecification::factory()->for($csCourse)->create([
            'title' => 'Data Structures',
            'credit_units' => 3,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($csSpec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);
        CurriculumEntry::factory()->for($bscsCurriculum)->for($csSpec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeSecondSemester,
        ]);
        $readyCohort = TermCohort::factory()->for($term)->for($bscs)->create([
            'curriculum_version_id' => $bscsCurriculum->id,
            'year_level' => 1,
            'reference' => 'BSCS 1-1',
            'confirmed_count' => 30,
            'confirmed_at' => now(),
        ]);

        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->assertTableActionVisible('loadCohortCurriculum', $readyCohort)
            ->assertTableActionHidden('openCohortCurriculum', $readyCohort)
            ->mountTableAction('loadCohortCurriculum', $readyCohort)
            ->assertSet('mountedActions.0.name', 'loadCurriculumSubjects')
            ->assertSet('mountedActions.0.data.term_cohort_id', $readyCohort->id)
            ->assertMountedActionModalSee('CS 102');
    }

    public function test_calendar_break_edit_rebinds_confirmed_sections_without_orphan_deadlock(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $v1 = TermCalendarPackage::factory()->for($term)->create([
            'version' => 1,
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
            'recorded_by' => $registrar->id,
        ]);
        TermTeachingGridRow::factory()->for($v1, 'package')->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);

        $course = $this->createConfirmedClassInTerm($term, $v1, 'IT 211', 'BSIT 2-A');
        $confirmedSection = Section::query()->where('term_calendar_package_id', $v1->id)->whereNotNull('confirmed_at')->firstOrFail();

        $teacher = $this->staff(User::StaffRoleFaculty);
        FacultyQualification::factory()->create(['faculty_user_id' => $teacher->id, 'course_id' => $course->id]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $teacher,
            actor: $teacher,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );
        Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 45]);

        $this->actingAs($registrar)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);
        $before = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $this->assertTrue($before['ready']);

        $unconfirmedSection = Section::factory()->for($confirmedSection->termOffering, 'termOffering')->create([
            'course_specification_id' => $confirmedSection->course_specification_id,
            'term_calendar_package_id' => $v1->id,
            'code' => 'IT211-DRAFT',
            'state' => Section::StateOpen,
            'confirmed_at' => null,
        ]);

        $v2 = TermCalendarPackage::factory()->for($term)->create([
            'version' => 2,
            'state' => TermCalendarPackage::StateDraft,
        ]);
        TermTeachingGridRow::factory()->for($v2, 'package')->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [
                ['starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ],
        ]);

        $activatedV2 = app(ActivateTermCalendarPackage::class)->execute($v2, $registrar);

        $this->assertSame(TermCalendarPackage::StateClosed, $v1->fresh()->state);
        $this->assertSame(TermCalendarPackage::StateActive, $activatedV2->state);
        $this->assertSame($activatedV2->id, $confirmedSection->fresh()->term_calendar_package_id);
        $this->assertSame($v1->id, $unconfirmedSection->fresh()->term_calendar_package_id);

        $after = app(ReadyTermPlanningProjection::class)->forTerm($term->fresh());
        $this->assertTrue($after['ready']);
        $this->assertSame([], $after['blockers']);
    }

    public function test_pre_generation_gate_blocks_whole_term_faculty_unit_overload_and_section_weekly_hours_overflow(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create([
            'state' => Term::StateActive,
            'default_max_units' => 3,
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
            'recorded_by' => $registrar->id,
        ]);
        // Only 4 open hours/week on Monday (08:00-12:00).
        TermTeachingGridRow::factory()->for($package, 'package')->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'breaks' => [],
        ]);

        $program = Program::factory()->create(['is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $cohort = TermCohort::factory()->for($term)->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSIT 1-OVER',
            'confirmed_count' => 30,
            'confirmed_at' => now(),
        ]);

        $teacherA = User::factory()->create(['status' => User::StatusActive]);
        $teacherA->assignRole(User::StaffRoleFaculty);
        $teacherB = User::factory()->create(['status' => User::StatusActive]);
        $teacherB->assignRole(User::StaffRoleFaculty);

        foreach ([$teacherA, $teacherB] as $teacher) {
            app(RecordFacultyAvailabilityDeclaration::class)->execute(
                term: $term,
                faculty: $teacher,
                actor: $teacher,
                availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
            );
        }

        // Three 3-unit (2x90 = 3 hrs/wk) classes for the same cohort = 9 units & 9 hrs/wk.
        // Combined faculty capacity = 2 * 3 = 6 units (< 9 units), and cohort weekly hours = 9 hrs (> 4 hrs open).
        foreach (['IT 101', 'IT 102', 'IT 103'] as $code) {
            $course = Course::factory()->create(['code' => $code, 'state' => Course::StateActive]);
            $spec = CourseSpecification::factory()->for($course)->create([
                'title' => 'Subject '.$code,
                'credit_units' => 3,
                'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
                'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
                'state' => CourseSpecification::StateActive,
            ]);
            $component = CourseComponent::factory()->for($spec)->create([
                'component_type' => CourseComponent::TypeLecture,
                'meeting_pattern' => '2x90',
                'weekly_contact_hours' => 3,
                'room_type_default' => CourseComponent::RoomTypeLectureRoom,
            ]);
            $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
                'year_level' => '1',
                'term_type' => $term->type,
            ]);
            $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => TermOffering::StatePendingScheduling,
            ]);
            $section = Section::factory()->for($offering, 'termOffering')->create([
                'course_specification_id' => $spec->id,
                'term_calendar_package_id' => $package->id,
                'code' => str_replace(' ', '', $code).'-A',
                'state' => Section::StateOpen,
                'confirmed_at' => now(),
            ]);
            $section->cohorts()->attach($cohort->id, ['expected_count' => 30]);
            $deliveryGroup = SectionDeliveryGroup::factory()->for($section)->create(['expected_count' => 30]);
            SchedulingDemand::factory()->create([
                'term_offering_id' => $offering->id,
                'section_delivery_group_id' => $deliveryGroup->id,
                'course_component_id' => $component->id,
                'meeting_count' => 2,
                'required_duration_minutes' => 90,
                'modality' => TermOffering::ModalityFaceToFace,
            ]);

            FacultyQualification::factory()->create(['faculty_user_id' => $teacherA->id, 'course_id' => $course->id]);
            FacultyQualification::factory()->create(['faculty_user_id' => $teacherB->id, 'course_id' => $course->id]);
        }

        Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 45]);

        $projection = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $codes = array_column($projection['blockers'], 'code');

        $this->assertFalse($projection['ready']);
        $this->assertContains('faculty_unit_capacity_exceeded', $codes);
        $this->assertContains('section_weekly_hours_exceeded', $codes);
    }

    public function test_published_term_step3_suppresses_pre_publish_banners_and_deep_links_step2_revision(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $package = $this->activeCreateTermCalendar($term, $registrar);

        $coveredCourse = $this->createConfirmedClassInTerm($term, $package, 'IT 301', 'BSIT 3-A');
        $coveredSection = Section::query()->where('term_calendar_package_id', $package->id)->where('code', 'IT301-A')->firstOrFail();
        $coveredDemand = $coveredSection->deliveryGroups()->firstOrFail()->schedulingDemands()->firstOrFail();

        // Create an uncovered confirmed class so pre-publish banners would fire if not suppressed.
        $this->createConfirmedClassInTerm($term, $package, 'IT 302', 'BSIT 3-B');

        $room = Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 40]);
        $lateTeacher = User::factory()->create([
            ...User::staffNamePayload('Elena', null, 'Navarro'),
            'status' => User::StatusActive,
        ]);
        $lateTeacher->assignRole(User::StaffRoleFaculty);
        FacultyQualification::factory()->create(['faculty_user_id' => $lateTeacher->id, 'course_id' => $coveredCourse->id]);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $lateTeacher,
            actor: $lateTeacher,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusPublished,
            'published_at' => now(),
            'published_by' => $registrar->id,
        ]);
        $officialVersion = PublishedTimetableVersion::factory()->for($term)->create([
            'schedule_run_id' => $run->id,
            'state' => PublishedTimetableVersion::StatePublished,
            'published_at' => now(),
        ]);
        $affectedMeeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $officialVersion->id,
            'scheduling_demand_id' => $coveredDemand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $lateTeacher->id,
            'room_id' => $room->id,
            'day_of_week' => 2,
            'starts_at' => '14:00:00',
            'ends_at' => '15:30:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);

        // Late change conflicts with Tuesday 14:00-15:30 live class.
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $lateTeacher,
            actor: $lateTeacher,
            correctionReason: 'Clinic duty on Tuesday afternoon',
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
        );

        $expectedHref = TermTimetable::getUrl([
            'termId' => $term->id,
            'timetableState' => 'official',
            'selectedVersionId' => $officialVersion->id,
            'action' => 'revisePublishedSchedule',
            'meetingIds' => (string) $affectedMeeting->id,
            'timetableView' => 'faculty',
            'viewFilter' => 'Elena Navarro',
        ]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertDontSee('needs a qualified teacher')
            ->assertDontSee('waiting on teacher availability')
            ->assertDontSee('Room readiness needs attention before scheduling.')
            ->assertSee('Action needed')
            ->assertSee('1 live class affected')
            ->assertSee('Start timetable revision')
            ->assertSeeHtml('href="'.e($expectedHref).'"')
            ->assertSeeHtml('action=revisePublishedSchedule')
            ->assertSeeHtml('meetingIds='.$affectedMeeting->id);

        $timetableComponent = Livewire::withQueryParams([
            'termId' => $term->id,
            'timetableState' => 'official',
            'selectedVersionId' => $officialVersion->id,
            'action' => 'revisePublishedSchedule',
            'meetingIds' => (string) $affectedMeeting->id,
        ])
            ->test(TermTimetable::class)
            ->assertSet('defaultAction', 'revisePublishedSchedule')
            ->assertSet('meetingIds', (string) $affectedMeeting->id)
            ->assertSeeHtml('revisePublishedSchedule');

        $timetableComponent->mountAction('revisePublishedSchedule')
            ->assertActionMounted('revisePublishedSchedule')
            ->assertActionDataSet(['section_meeting_ids' => [$affectedMeeting->id]])
            ->assertMountedActionModalSee('Choose change')
            ->assertMountedActionModalDontSee('Check the impact');
    }

    public function test_discard_blocking_draft_and_generate_checks_schedule_run_id_without_sql_error(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $package = $this->activeCreateTermCalendar($term, $registrar);
        $course = $this->createConfirmedClassInTerm($term, $package, 'IT 402', 'BSIT 4-A');
        $demand = Section::query()
            ->where('term_calendar_package_id', $package->id)
            ->where('code', 'IT402-A')
            ->firstOrFail()
            ->deliveryGroups()
            ->firstOrFail()
            ->schedulingDemands()
            ->firstOrFail();

        $teacher = $this->staff(User::StaffRoleFaculty);
        FacultyQualification::factory()->create(['faculty_user_id' => $teacher->id, 'course_id' => $course->id]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $teacher,
            actor: $teacher,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );
        $room = Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 45]);

        $this->actingAs($registrar)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);

        // candidate_state = Accepted & published_at = null exercises PublishedTimetableVersion::where('schedule_run_id', ...).
        $blockingRun = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'Accepted',
            'published_at' => null,
        ]);
        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $blockingRun->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $teacher->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'time_block_key' => 'mon-0800-0930',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        $freshRunId = null;
        $this->mock(ScheduleGenerationService::class)
            ->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function () use ($term, $registrar, &$freshRunId): ScheduleGenerationRun {
                $run = ScheduleGenerationRun::factory()->for($term)->create([
                    'requested_by' => $registrar->id,
                    'status' => ScheduleGenerationRun::StatusQueued,
                    'candidate_state' => null,
                ]);
                $freshRunId = $run->id;

                return $run;
            });

        $component = Livewire::withQueryParams([
            'termId' => $term->id,
            'timetableState' => 'draft',
            'selectedRunId' => $blockingRun->id,
        ])
            ->test(TermTimetable::class);

        $component->call('discardBlockingDraftAndGenerate', $blockingRun->id)
            ->assertHasNoErrors();

        $this->assertNotNull($freshRunId);
        $component->assertSet('selectedRunId', $freshRunId)
            ->assertNotified('Regenerating draft timetable');
        $this->assertSame('Rejected', $blockingRun->fresh()->candidate_state);
    }

    private function activeCreateTermCalendar(Term $term, User $registrar): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
            'recorded_by' => $registrar->id,
        ]);
        TermTeachingGridRow::factory()->for($package, 'package')->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);
        TermTeachingGridRow::factory()->for($package, 'package')->create([
            'day_of_week' => 2,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);

        return $package;
    }

    private function createReadyDraftCurriculum(Program $program, string $name, string $courseCode): CurriculumVersion
    {
        $draft = CurriculumVersion::factory()->for($program)->create([
            'name' => $name,
            'state' => CurriculumVersion::StateDraft,
        ]);
        $course = Course::factory()->create(['code' => $courseCode]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Subject '.$courseCode,
            'credit_units' => 3,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);
        CurriculumEntry::factory()->for($draft)->for($spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);

        return $draft;
    }

    private function createActiveCalendarPackage(Term $term): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        TermTeachingGridRow::factory()->for($package, 'package')->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);

        return $package;
    }

    private function createConfirmedClassInTerm(
        Term $term,
        TermCalendarPackage $package,
        string $courseCode,
        string $cohortReference,
    ): Course {
        $program = Program::factory()->create(['is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => $courseCode, 'state' => Course::StateActive]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Title '.$courseCode,
            'credit_units' => 3,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        $component = CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => $term->type,
        ]);
        $cohort = TermCohort::factory()->for($term)->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
            'reference' => $cohortReference,
            'confirmed_count' => 30,
            'confirmed_at' => now(),
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'course_specification_id' => $spec->id,
            'term_calendar_package_id' => $package->id,
            'code' => str_replace(' ', '', $courseCode).'-A',
            'state' => Section::StateOpen,
            'confirmed_at' => now(),
        ]);
        $section->cohorts()->attach($cohort->id, ['expected_count' => 30]);
        $deliveryGroup = SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 30,
        ]);
        SchedulingDemand::factory()->create([
            'term_offering_id' => $offering->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'course_component_id' => $component->id,
            'meeting_count' => 2,
            'required_duration_minutes' => 90,
            'modality' => TermOffering::ModalityFaceToFace,
        ]);

        return $course;
    }

    public function test_cancelled_and_technical_failure_runs_return_zero_fake_conflict_cards(): void
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');

        $term = Term::factory()->create([
            'state' => Term::StateActive,
            'default_max_units' => 24,
        ]);
        $teacher = User::factory()->create([
            ...User::staffNamePayload('Marco', null, 'Cruz'),
            'status' => User::StatusActive,
        ]);
        $teacher->assignRole(User::StaffRoleFaculty);

        $snapshot = [
            'term' => [
                'default_max_units' => 24,
                'teaching_grid_rows' => [
                    ['day_of_week' => 1, 'starts_at' => '07:00:00', 'ends_at' => '19:00:00'],
                ],
            ],
            'faculty' => [
                ['faculty_id' => $teacher->id, 'max_allowed_units' => 24],
            ],
            'faculty_availability' => [
                [
                    'faculty_user_id' => $teacher->id,
                    'availability_mode' => FacultyAvailabilityDeclaration::ModeAnyApproved,
                    'teaching_windows' => [],
                    'hard_unavailability' => [],
                ],
            ],
            'rooms' => [
                [
                    'room_id' => 1,
                    'code' => 'RM-101',
                    'name' => 'Room 101',
                    'room_type' => Room::TypeLectureRoom,
                    'capacity' => 45,
                    'feature_keys' => [],
                ],
            ],
            'scheduling_demands' => [
                [
                    'scheduling_demand_id' => 501,
                    'term_offering_id' => 601,
                    'section_delivery_group_id' => 701,
                    'course_code' => 'IT 101',
                    'component_type' => CourseComponent::TypeLecture,
                    'load_units' => 3,
                    'meeting_count' => 2,
                    'required_duration_minutes' => 90,
                    'modality' => TermOffering::ModalityFaceToFace,
                    'room_required' => true,
                    'room_type_requirement' => CourseComponent::RoomTypeLectureRoom,
                    'expected_count' => 35,
                    'eligible_faculty_user_ids' => [$teacher->id],
                    'cohort_or_student_group_ids' => [1],
                ],
            ],
        ];

        $cancelledRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusFailed,
            'candidate_review_reason' => 'Cancelled while in progress by Maribel Dizon.',
            'input_snapshot' => $snapshot,
            'diagnostics' => [
                'solver_dispatch' => [
                    'status' => 'cancelled',
                    'latest_outcome' => 'cancelled',
                    'failure' => [
                        'code' => 'CANCELLED_BY_REGISTRAR',
                        'message' => 'Timetable generation was cancelled by Maribel Dizon.',
                        'retryable' => true,
                        'final' => true,
                    ],
                ],
            ],
        ]);

        $technicalRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusFailed,
            'input_snapshot' => $snapshot,
            'diagnostics' => [
                'solver_dispatch' => [
                    'status' => 'failed',
                    'failure' => [
                        'code' => 'SOLVER_UNAVAILABLE',
                        'message' => 'Connection timed out while contacting solver.',
                    ],
                ],
            ],
        ]);

        $service = app(TimetableConflictDiagnosticService::class);

        $cancelledDiagnosis = $service->diagnose($cancelledRun, $term);
        $this->assertFalse($cancelledDiagnosis['has_conflicts']);
        $this->assertSame([], $cancelledDiagnosis['items']);

        $technicalDiagnosis = $service->diagnose($technicalRun, $term);
        $this->assertFalse($technicalDiagnosis['has_conflicts']);
        $this->assertSame([], $technicalDiagnosis['items']);

        $page = new TermTimetable;
        $summary = $page->plainSolverFailureSummary(
            ['code' => 'CANCELLED_BY_REGISTRAR', 'message' => 'Timetable generation was cancelled by Maribel Dizon.'],
            [],
        );
        $this->assertStringContainsString('cancelled', strtolower($summary['explanation']));
        $this->assertStringContainsString('schedule data was not changed', strtolower($summary['nextStep']));
    }

    private function staff(string $roleName): User
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $role = Role::findOrCreate($roleName, 'web');
        if ($roleName === User::StaffRoleRegistrar) {
            $role->syncPermissions([
                Permission::findOrCreate('manage-schedules', 'web'),
                Permission::findOrCreate('approve-documents', 'web'),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $roleName]);

        return $user;
    }
}
