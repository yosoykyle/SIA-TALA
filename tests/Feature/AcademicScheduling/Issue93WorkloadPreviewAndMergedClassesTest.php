<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\RecordTermCohort;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\Sections\Pages\ViewSection;
use App\Models\AcademicYear;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Program;
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
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93WorkloadPreviewAndMergedClassesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_term_resources_assign_subjects_and_edit_faculty_subjects_render_live_workload_preview_non_blocking_warnings_and_stable_assigned_filter(): void
    {
        $this->actingAsRegistrar();
        [$term, $package, , $curriculum] = $this->activeTermContext([1, 3]);

        $primaryFaculty = $this->makeFaculty('Clara', 'Reyes');
        $backupFaculty = $this->makeFaculty('Mateo', 'Lim');

        // Sole-teacher course: 2 sections x (3 units, 3 hrs/week = 1x180) => 2 classes · 6 units · 6 hrs/week.
        [$exclusiveCourse, $exclusiveSpec] = $this->createCourseWithComponent('IT 301', 'Systems Analysis', 3.0, 3.0, '1x180');
        $exclusiveEntry = CurriculumEntry::factory()
            ->for($curriculum, 'curriculumVersion')
            ->for($exclusiveSpec, 'courseSpecification')
            ->create(['year_level' => 'Third Year', 'term_type' => $term->type]);
        $exclusiveOffering = TermOffering::factory()
            ->for($term)
            ->for($exclusiveEntry, 'curriculumEntry')
            ->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StatePendingScheduling]);
        $this->createConfirmedSection($exclusiveOffering, $package, $exclusiveSpec, 'IT301-A');
        $this->createConfirmedSection($exclusiveOffering, $package, $exclusiveSpec, 'IT301-B');

        // Shared-pool course: 1 section x (3 units, 3 hrs/week = 2x90) where backupFaculty is already qualified.
        [$sharedCourse, $sharedSpec] = $this->createCourseWithComponent('IT 302', 'Software Engineering', 3.0, 3.0, '2x90');
        $sharedEntry = CurriculumEntry::factory()
            ->for($curriculum, 'curriculumVersion')
            ->for($sharedSpec, 'courseSpecification')
            ->create(['year_level' => 'Third Year', 'term_type' => $term->type]);
        $sharedOffering = TermOffering::factory()
            ->for($term)
            ->for($sharedEntry, 'curriculumEntry')
            ->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StatePendingScheduling]);
        $this->createConfirmedSection($sharedOffering, $package, $sharedSpec, 'IT302-A');

        FacultyQualification::factory()->create([
            'faculty_user_id' => $backupFaculty->id,
            'course_id' => $sharedCourse->id,
            'is_active' => true,
        ]);

        // Give primaryFaculty a 4-unit override cap (24 default - 20 = 4) and 4 hrs/week submitted open hours.
        FacultyTermLoadOverride::factory()->create([
            'faculty_user_id' => $primaryFaculty->id,
            'term_id' => $term->id,
            'default_max_units_snapshot' => 4,
            'approved_overload_units' => 0,
            'authority' => 'Dean Memo 2026-14',
            'reason' => 'Research load reduction',
            'is_active' => true,
        ]);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $primaryFaculty,
            actor: $primaryFaculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
            ],
        );

        // 1. Header assignSubjects modal: live preview splits exclusive vs shared, shows override cap + submitted open hours + amber warning, AND allows non-blocking save.
        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class);
        $page->mountAction('assignSubjects')
            ->setActionData([
                'faculty_user_id' => $primaryFaculty->id,
                'program_filter' => 'all',
                'course_ids' => [(string) $exclusiveCourse->id, (string) $sharedCourse->id],
            ])
            ->assertMountedActionModalSee('Term workload preview')
            ->assertMountedActionModalSee('4 units (override)')
            ->assertMountedActionModalSee('4 hrs/week (submitted)')
            ->assertMountedActionModalSee('Sole-teacher (exclusive)')
            ->assertMountedActionModalSee('2 classes · 6 units · 6 hrs/week')
            ->assertMountedActionModalSee('Shared-pool')
            ->assertMountedActionModalSee('1 class · 3 units · 3 hrs/week')
            ->assertMountedActionModalSee('Sole-teacher load (6 units) exceeds unit cap (4 units).')
            ->assertMountedActionModalSee('Sole-teacher load (6 hrs/week) exceeds submitted open hours (4 hrs/week, short 2 hrs).')
            ->assertMountedActionModalSee('You can still save and assign backup teachers to share the load.')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Qualified subjects updated');

        $savedCourseIds = FacultyQualification::query()
            ->active()
            ->where('faculty_user_id', $primaryFaculty->id)
            ->orderBy('course_id')
            ->pluck('course_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $expectedSavedIds = [(int) $exclusiveCourse->id, (int) $sharedCourse->id];
        sort($expectedSavedIds);
        $this->assertSame($expectedSavedIds, $savedCourseIds);

        // 2. Per-row editFacultySubjects modal: shows live preview too, AND unchecking under 'assigned' filter keeps the unchecked subject visible while updating live preview.
        $freshPage = Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class);
        $freshPage->mountTableAction('editFacultySubjects', 'faculty-'.$primaryFaculty->id)
            ->assertMountedActionModalSee('Term workload preview')
            ->assertMountedActionModalSee('2 classes · 6 units · 6 hrs/week')
            ->assertMountedActionModalSee('1 class · 3 units · 3 hrs/week')
            ->setTableActionData(['program_filter' => 'assigned'])
            ->setTableActionData(['course_ids' => [(string) $sharedCourse->id]])
            ->assertMountedActionModalSee('0 classes · 0 units · 0 hrs/week')
            ->assertMountedActionModalSee('1 class · 3 units · 3 hrs/week')
            ->assertMountedActionModalDontSee('exceeds unit cap')
            // Unchecked exclusiveCourse stays visible in the 'assigned' filter list instead of vanishing mid-edit.
            ->assertMountedActionModalSee('IT 301')
            ->assertMountedActionModalSee('IT 302')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Subjects updated for Clara Reyes');

        $remainingCourseIds = FacultyQualification::query()
            ->active()
            ->where('faculty_user_id', $primaryFaculty->id)
            ->pluck('course_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $this->assertSame([(int) $sharedCourse->id], $remainingCourseIds);
    }

    public function test_my_availability_hours_target_tracks_confirmed_sole_teacher_load_and_flags_short_submissions_under_needs_review(): void
    {
        $this->actingAsRegistrar();
        [$term, $package, , $curriculum] = $this->activeTermContext([1, 3]);

        $shortFaculty = $this->makeFaculty('Nora', 'Diaz');
        $metFaculty = $this->makeFaculty('Victor', 'Tan');

        // Confirmed sole-teacher classes for Nora:
        // - Course 1: 1x180 (3 hrs uninterrupted lab)
        // - Course 2: 2x90 (3 hrs lecture)
        // Total required = 6 hrs/week, longest class = 3 hrs.
        [$labCourse, $labSpec] = $this->createCourseWithComponent('CS 210', 'Operating Systems Lab', 3.0, 3.0, '1x180');
        $labEntry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($labSpec, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        $labOffering = TermOffering::factory()->for($term)->for($labEntry, 'curriculumEntry')->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StatePendingScheduling]);
        $this->createConfirmedSection($labOffering, $package, $labSpec, 'CS210-A');

        [$lecCourse, $lecSpec] = $this->createCourseWithComponent('CS 211', 'Discrete Structures', 3.0, 3.0, '2x90');
        $lecEntry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($lecSpec, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        $lecOffering = TermOffering::factory()->for($term)->for($lecEntry, 'curriculumEntry')->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StatePendingScheduling]);
        $this->createConfirmedSection($lecOffering, $package, $lecSpec, 'CS211-A');

        // Unconfirmed section should NOT inflate Nora's MyAvailability target (confirmedOnly: true).
        [$draftCourse, $draftSpec] = $this->createCourseWithComponent('CS 299', 'Unconfirmed Elective', 3.0, 4.0, '1x240');
        $draftEntry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($draftSpec, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        $draftOffering = TermOffering::factory()->for($term)->for($draftEntry, 'curriculumEntry')->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StatePendingScheduling]);
        Section::factory()->for($draftOffering, 'termOffering')->create([
            'code' => 'CS299-DRAFT',
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $draftSpec->id,
            'confirmed_at' => null,
            'state' => Section::StatePlanned,
        ]);

        // Confirmed class for Victor (3 hrs/week, 1x90) so he appears in-term and meets his target.
        [$metCourse, $metSpec] = $this->createCourseWithComponent('CS 105', 'Intro Computing', 3.0, 3.0, '2x90');
        $metEntry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($metSpec, 'courseSpecification')->create(['year_level' => 'First Year', 'term_type' => $term->type]);
        $metOffering = TermOffering::factory()->for($term)->for($metEntry, 'curriculumEntry')->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StatePendingScheduling]);
        $this->createConfirmedSection($metOffering, $package, $metSpec, 'CS105-A');

        foreach ([$labCourse, $lecCourse, $draftCourse] as $course) {
            FacultyQualification::factory()->create([
                'faculty_user_id' => $shortFaculty->id,
                'course_id' => $course->id,
                'is_active' => true,
            ]);
        }
        FacultyQualification::factory()->create([
            'faculty_user_id' => $metFaculty->id,
            'course_id' => $metCourse->id,
            'is_active' => true,
        ]);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $metFaculty,
            actor: $metFaculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        // Live target progression in MyAvailability for Nora:
        $this->actingAs($shortFaculty)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleFaculty]);
        $availabilityPage = Livewire::actingAs($shortFaculty)
            ->test(MyAvailability::class, ['termId' => $term->id])
            ->assertSee('Hours target: 6 hrs/week min (longest class 3 hrs)')
            ->assertSeeHtml('tala-availability-target-pill tabular-nums is-neutral');

        // Short on total hours (Mon 08:00-10:00 => 2 hrs/week, short 4 hrs).
        $availabilityPage
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeWindows)
            ->call('toggleDay', 1)
            ->set('dayWindows.1.0.start_time', '08:00')
            ->set('dayWindows.1.0.end_time', '10:00')
            ->assertSee('Hours target: 6 hrs/week min (longest class 3 hrs) — short 4 hrs/week; Registrar will review')
            ->assertSeeHtml('tala-availability-target-pill tabular-nums is-short');

        // Enough total hours (6 hrs across three 2-hour blocks), BUT longest uninterrupted block is 2 hrs < 3 hrs lab!
        $availabilityPage
            ->call('addDayRange', 1)
            ->set('dayWindows.1.1.start_time', '13:00')
            ->set('dayWindows.1.1.end_time', '15:00')
            ->call('toggleDay', 3)
            ->set('dayWindows.3.0.start_time', '08:00')
            ->set('dayWindows.3.0.end_time', '10:00')
            ->assertSee('Hours target: 6 hrs/week min (longest class 3 hrs) — longest open block is 2 hrs; Registrar will review')
            ->assertSeeHtml('tala-availability-target-pill tabular-nums is-short');

        // Meets both total hours (6 hrs/week) and longest uninterrupted block (4 hrs >= 3 hrs) => green.
        $availabilityPage
            ->set('dayWindows.1', [['start_time' => '08:00', 'end_time' => '12:00']])
            ->set('dayWindows.3', [['start_time' => '08:00', 'end_time' => '10:00']])
            ->assertSee('Hours target met: 6 hrs/week min (longest class 3 hrs)')
            ->assertSeeHtml('tala-availability-target-pill tabular-nums is-met');

        // Honest short submission (Mon 08:00-11:00 = 3 hrs/week) is NOT hard-blocked.
        $availabilityPage
            ->call('toggleDay', 3)
            ->set('dayWindows.1', [['start_time' => '08:00', 'end_time' => '11:00']])
            ->call('saveAvailability')
            ->assertHasNoErrors()
            ->assertNotified('Availability response saved');

        $savedDeclaration = FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->where('faculty_user_id', $shortFaculty->id)
            ->sole();
        $this->assertSame(FacultyAvailabilityDeclaration::ModeWindows, $savedDeclaration->availability_mode);

        // Registrar Step 3 TermResources surfaces Nora under Needs review (triage count, notice, row cue, and filter).
        $this->actingAsRegistrar();
        $resourcesPage = Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class);

        $triageCounts = $resourcesPage->instance()->facultyTriageCounts();
        $this->assertSame(1, $triageCounts['needs_review']);

        $resourcesPage
            ->assertSee('1 review needed')
            ->assertSee('Hours short: 1 teacher submitted fewer open hours than their confirmed sole-teacher classes require.')
            ->assertSee('Nora Diaz')
            ->assertSee('Hours short')
            ->assertSee('3 / 6 hrs/week open (short 3 hrs)')
            ->filterTable('availability_status', 'needs_review')
            ->assertSee('Nora Diaz')
            ->assertDontSee('Victor Tan');
    }

    public function test_term_classes_displays_cross_program_merged_programs_cleanly_and_enforces_curriculum_containment_in_edit_class(): void
    {
        $registrar = $this->actingAsRegistrar();
        [$term, $package, $bsitProgram, $bsitCurriculum] = $this->activeTermContext([1, 2, 3, 4, 5], 'BSIT');

        Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 80]);

        $bscsProgram = Program::factory()->create([
            'code' => 'BSCS',
            'name' => 'Bachelor of Science in Computer Science',
        ]);
        $bscsCurriculum = CurriculumVersion::factory()->for($bscsProgram)->create([
            'name' => 'BSCS Curriculum 2026',
            'state' => CurriculumVersion::StateActive,
        ]);

        $bsbaProgram = Program::factory()->create([
            'code' => 'BSBA',
            'name' => 'Bachelor of Science in Business Administration',
        ]);
        $bsbaCurriculum = CurriculumVersion::factory()->for($bsbaProgram)->create([
            'name' => 'BSBA Curriculum 2026',
            'state' => CurriculumVersion::StateActive,
        ]);

        // Host course GE 101 in BSIT curriculum.
        [$geCourse, $geSpec] = $this->createCourseWithComponent('GE 101', 'Understanding the Self', 3.0, 3.0, '2x90');
        $geEntry = CurriculumEntry::factory()
            ->for($bsitCurriculum, 'curriculumVersion')
            ->for($geSpec, 'courseSpecification')
            ->create(['year_level' => 'First Year', 'term_type' => $term->type]);

        // Approved equivalent course CS-GE 101 in BSCS curriculum.
        [$equivCourse, $equivSpec] = $this->createCourseWithComponent('CS-GE 101', 'Understanding the Self (CS Track)', 3.0, 3.0, '2x90');
        CourseRequirement::factory()->create([
            'course_specification_id' => $geSpec->id,
            'rule_type' => CourseRequirement::TypeEquivalency,
            'related_course_id' => $equivCourse->id,
            'state' => CourseRequirement::StateActive,
        ]);
        CurriculumEntry::factory()
            ->for($bscsCurriculum, 'curriculumVersion')
            ->for($equivSpec, 'courseSpecification')
            ->create(['year_level' => 'First Year', 'term_type' => $term->type]);

        // Unrelated course BA 101 in BSBA curriculum (does NOT contain GE 101 or an equivalent).
        [, $baSpec] = $this->createCourseWithComponent('BA 101', 'Principles of Management', 3.0, 3.0, '2x90');
        CurriculumEntry::factory()
            ->for($bsbaCurriculum, 'curriculumVersion')
            ->for($baSpec, 'courseSpecification')
            ->create(['year_level' => 'First Year', 'term_type' => $term->type]);

        $bsitCohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $bsitCurriculum->id,
            'reference' => 'BSIT 1-A',
            'confirmed_count' => 25,
        ]);
        $bscsCohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $bscsCurriculum->id,
            'reference' => 'BSCS 1-A',
            'confirmed_count' => 20,
        ]);
        $bsbaCohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $bsbaCurriculum->id,
            'reference' => 'BSBA 1-A',
            'confirmed_count' => 20,
        ]);

        $offering = TermOffering::factory()
            ->for($term)
            ->for($geEntry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'expected_count' => 25,
                'state' => TermOffering::StatePendingScheduling,
            ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'GE101-A',
            'capacity' => 50,
            'state' => Section::StatePlanned,
        ]);
        SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'GE101-A',
            'expected_count' => 25,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section = app(PrepareClassOffering::class)->execute($term, $section, $registrar, [
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        $section = app(ConfirmClassOffering::class)->execute($section, $registrar, [$bsitCohort->id => 25]);

        $classesPage = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'classes'])->test(TermClasses::class);

        // 1. Rejects linking BSBA 1-A because its curriculum does NOT contain GE 101 or an approved equivalent.
        $classesPage->mountTableAction('editClass', $section)
            ->setTableActionData([
                'capacity' => 50,
                'delivery_mode' => TermOffering::ModalityFaceToFace,
                'lecture_room_type' => CourseComponent::RoomTypeLectureRoom,
                'cohorts' => [
                    ['term_cohort_id' => $bsitCohort->id, 'expected_count' => 25],
                    ['term_cohort_id' => $bsbaCohort->id, 'expected_count' => 20],
                ],
            ])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['cohorts'])
            ->assertNotified('Cannot save class changes')
            ->call('unmountAction');

        $this->assertSame(
            [$bsitCohort->id],
            $section->fresh()->cohorts()->pluck('term_cohorts.id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );

        // 2. Accepts linking BSCS 1-A because its curriculum contains approved equivalent CS-GE 101.
        $classesPage->mountTableAction('editClass', $section)
            ->setTableActionData([
                'capacity' => 50,
                'delivery_mode' => TermOffering::ModalityFaceToFace,
                'lecture_room_type' => CourseComponent::RoomTypeLectureRoom,
                'cohorts' => [
                    ['term_cohort_id' => $bsitCohort->id, 'expected_count' => 25],
                    ['term_cohort_id' => $bscsCohort->id, 'expected_count' => 20],
                ],
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Class and room requirements updated');

        $linkedCohortIds = $section->fresh()->cohorts()
            ->orderBy('term_cohorts.id')
            ->pluck('term_cohorts.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $expectedCohortIds = [$bsitCohort->id, $bscsCohort->id];
        sort($expectedCohortIds);
        $this->assertSame($expectedCohortIds, $linkedCohortIds);

        // 3. TermClasses table displays all linked program codes cleanly (BSIT · BSCS + Merged · 2 programs) without per-row N+1 program queries.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $mergedTable = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'classes'])->test(TermClasses::class);
        $singleSectionQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $mergedTable
            ->assertSee('BSIT · BSCS')
            ->assertSee('Merged · 2 programs')
            ->assertSee('BSIT 1-A')
            ->assertSee('BSCS 1-A');

        $singleSectionProgramQueries = collect($singleSectionQueries)
            ->filter(static fn (array $query): bool => str_contains(strtolower($query['query']), 'from `programs`'))
            ->count();

        // Add 3 more merged sections and confirm `programs` query count stays constant (no N+1 per row).
        for ($i = 2; $i <= 4; $i++) {
            $extraSection = Section::factory()->for($offering, 'termOffering')->create([
                'code' => 'GE101-'.$i,
                'term_calendar_package_id' => $package->id,
                'course_specification_id' => $geSpec->id,
                'capacity' => 50,
                'state' => Section::StatePlanned,
                'confirmed_at' => now(),
            ]);
            $extraSection->cohorts()->attach([
                $bsitCohort->id => ['expected_count' => 15],
                $bscsCohort->id => ['expected_count' => 15],
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'classes'])->test(TermClasses::class)
            ->assertSee('BSIT · BSCS')
            ->assertSee('Merged · 2 programs');
        $multiSectionQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $multiSectionProgramQueries = collect($multiSectionQueries)
            ->filter(static fn (array $query): bool => str_contains(strtolower($query['query']), 'from `programs`'))
            ->count();
        $this->assertSame(
            $singleSectionProgramQueries,
            $multiSectionProgramQueries,
            'Adding more merged classes should not increase `programs` queries (no N+1).',
        );

        // 4. Section detail infolist appends merged additional program code cleanly.
        Livewire::test(ViewSection::class, ['record' => $section->id])
            ->assertSee('Bachelor of Science in Information Technology · First Year (+ BSCS)');
    }

    public function test_term_timetable_does_not_surface_conflict_guide_for_human_rejected_feasible_run(): void
    {
        $registrar = $this->actingAsRegistrar();
        [$term, $package, , $curriculum] = $this->activeTermContext([1, 2, 3]);
        $faculty = $this->makeFaculty('Rosario', 'Umali');
        $room = Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 40]);

        [$course, $spec] = $this->createCourseWithComponent('PATHFIT2', 'Fitness Exercises', 2.0, 2.0, '1x120');
        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $course->id, 'is_active' => true]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        $entry = CurriculumEntry::factory()
            ->for($curriculum, 'curriculumVersion')
            ->for($spec, 'courseSpecification')
            ->create(['year_level' => 'First Year', 'term_type' => $term->type]);
        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'DHM 1-1',
            'confirmed_count' => 25,
        ]);
        $offering = TermOffering::factory()
            ->for($term)
            ->for($entry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'expected_count' => 25,
                'state' => TermOffering::StatePendingScheduling,
            ]);
        $section = $this->createConfirmedSection($offering, $package, $spec, 'PATHFIT2-DHM11');
        SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'PATHFIT2-DHM11',
            'expected_count' => 25,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section->cohorts()->attach($cohort->id, ['expected_count' => 25]);

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $demand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->sole();

        // Term with 0 schedule runs ($activeRun === null) renders cleanly without null dereference errors.
        Livewire::withQueryParams([
            'termId' => $term->id,
            'timetableState' => 'draft',
        ])->test(TermTimetable::class)
            ->assertSee('No timetable yet')
            ->assertSee('Generate timetable');

        $feasibleRun = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'input_snapshot' => ['capture' => 'pending'],
            'input_hash' => hash('sha256', 'feasible-rejected-run'),
            'solver_version' => ScheduleGenerationRun::SolverVersion,
            'candidate_state' => 'UnderReview',
            'candidate_key' => 'cp-sat-rejected-feasible',
            'diagnostics' => [
                'solver_result' => [
                    'solver_status' => 'feasible',
                    'summary' => [
                        'status' => 'accepted',
                        'outcome' => 'Feasible',
                        'complete' => true,
                        'assigned_count' => 1,
                        'unassigned_count' => 0,
                        'hard_violation_count' => 0,
                        'conflict_count' => 0,
                        'independently_validated' => true,
                        'validated_meeting_count' => 1,
                    ],
                ],
            ],
        ]);
        app(ScheduleSolverSnapshotService::class)->captureForRun($feasibleRun);

        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $feasibleRun->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'time_block_key' => 'D1-0800',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        // Discarding a feasible run transitions status => StatusBlocked + candidate_state => 'Rejected'.
        $rejectedRun = app(ReviewTimetableCandidate::class)->reject(
            $feasibleRun,
            $registrar,
            'Discarded by Registrar to generate a fresh draft timetable.',
        );
        $this->assertSame(ScheduleGenerationRun::StatusBlocked, $rejectedRun->status);
        $this->assertSame('Rejected', $rejectedRun->candidate_state);

        $page = Livewire::withQueryParams([
            'termId' => $term->id,
            'timetableState' => 'draft',
            'selectedRunId' => $rejectedRun->id,
        ])->test(TermTimetable::class);

        $page
            ->assertDontSee('How to resolve these scheduling issues')
            ->assertDontSee('How to resolve this scheduling issue')
            ->assertDontSee('Unassignable class')
            ->assertDontSee('issues need attention');

        $diagnosis = $page->viewData('conflictDiagnosis');
        $this->assertFalse($diagnosis['has_conflicts']);
        $this->assertSame([], $diagnosis['items']);
    }

    public function test_term_overview_assign_batch_uses_native_select_with_curriculum_names_and_term_resources_shows_draft_teaching_load(): void
    {
        $registrar = $this->actingAsRegistrar();

        $ayPrior = AcademicYear::factory()->create([
            'label' => '2026-2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'state' => AcademicYear::StateActive,
        ]);
        $ayTarget = AcademicYear::factory()->create([
            'label' => '2028-2029',
            'starts_on' => '2028-08-01',
            'ends_on' => '2029-05-31',
            'state' => AcademicYear::StateDraft,
        ]);

        [$term, $package, $program, $curriculum] = $this->activeTermContext([1, 2, 3], 'BSIT');
        $curriculum->update(['name' => 'BSIT Curriculum (2026–2027)']);
        CurriculumVersionBatch::query()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'academic_year_id' => $ayPrior->id,
            'added_by' => $registrar->id,
        ]);

        // TermOverview assignAcademicYearBatch uses curriculum name (not old Batch 2026–27 label) and native <select>.
        Livewire::withQueryParams(['academicYearId' => $ayTarget->id])
            ->test(TermOverview::class)
            ->mountAction('assignAcademicYearBatch')
            ->assertMountedActionModalSee('BSIT · BSIT Curriculum (2026–2027)')
            ->assertMountedActionModalDontSee('BSIT · Batch 2026–27')
            ->assertMountedActionModalSee('Lists active programs without a curriculum batch assigned to 2028-2029.');

        // TermResources shows "Cap: 24 units / No timetable yet · Default" before any draft exists.
        $faculty = $this->makeFaculty('Alex', 'Navarro');
        $room = Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 40]);
        [$course, $spec] = $this->createCourseWithComponent('IT 101', 'Intro to Computing', 3.0, 3.0, '2x90');
        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $course->id, 'is_active' => true]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        $entry = CurriculumEntry::factory()
            ->for($curriculum, 'curriculumVersion')
            ->for($spec, 'courseSpecification')
            ->create(['year_level' => 'First Year', 'term_type' => $term->type]);
        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'BSIT 1-A',
            'confirmed_count' => 30,
        ]);
        $offering = TermOffering::factory()
            ->for($term)
            ->for($entry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityFaceToFace,
                'expected_count' => 30,
                'state' => TermOffering::StatePendingScheduling,
            ]);
        $section = $this->createConfirmedSection($offering, $package, $spec, 'IT101-1A');
        SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'IT101-1A',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section->cohorts()->attach($cohort->id, ['expected_count' => 30]);
        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $demand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->sole();

        Livewire::withQueryParams(['termId' => $term->id])
            ->test(TermResources::class)
            ->assertSee('Cap: 24 units')
            ->assertSee('No timetable yet · Default');

        // Once an UnderReview draft exists with 2 meetings for the same section, units & classes deduplicate cleanly (3 / 24 units, 1 draft class · Default).
        $draftRun = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'candidate_state' => 'UnderReview',
        ]);
        foreach ([1, 2] as $sequence) {
            CandidateScheduleRow::query()->create([
                'schedule_run_id' => $draftRun->id,
                'scheduling_demand_id' => $demand->id,
                'meeting_sequence' => $sequence,
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => $sequence,
                'starts_at' => '08:00:00',
                'ends_at' => '09:30:00',
                'time_block_key' => 'D'.$sequence.'-0800',
                'status' => CandidateScheduleRow::StatusOk,
            ]);
        }

        Livewire::withQueryParams(['termId' => $term->id])
            ->test(TermResources::class)
            ->assertSee('3 / 24 units')
            ->assertSee('1 draft class · Default');
    }

    private function actingAsRegistrar(): User
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

    private function makeFaculty(string $firstName, string $lastName): User
    {
        $role = Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $faculty = User::factory()->create([
            'first_name' => $firstName,
            'middle_name' => null,
            'last_name' => $lastName,
            'name' => $firstName.' '.$lastName,
            'status' => User::StatusActive,
        ]);
        $faculty->assignRole($role);

        return $faculty;
    }

    /**
     * @param  list<int>  $days
     * @return array{Term, TermCalendarPackage, Program, CurriculumVersion}
     */
    private function activeTermContext(array $days = [1, 3], string $programCode = 'BSIT'): array
    {
        $term = Term::factory()->create([
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
            'default_max_units' => 24,
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        foreach ($days as $day) {
            TermTeachingGridRow::factory()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
            ]);
        }

        $program = Program::factory()->create([
            'code' => $programCode,
            'name' => 'Bachelor of Science in Information Technology',
        ]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => $programCode.' Curriculum 2026',
            'state' => CurriculumVersion::StateActive,
        ]);

        return [$term, $package, $program, $curriculum];
    }

    /** @return array{Course, CourseSpecification} */
    private function createCourseWithComponent(
        string $code,
        string $title,
        float $units,
        float $weeklyHours,
        string $pattern,
    ): array {
        $course = Course::factory()->create(['code' => $code]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => $title,
            'state' => CourseSpecification::StateActive,
            'credit_units' => $units,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        CourseComponent::factory()->for($spec, 'courseSpecification')->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => $weeklyHours,
            'meeting_pattern' => $pattern,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);

        return [$course, $spec];
    }

    private function createConfirmedSection(
        TermOffering $offering,
        TermCalendarPackage $package,
        CourseSpecification $spec,
        string $code,
    ): Section {
        return Section::factory()->for($offering, 'termOffering')->create([
            'code' => $code,
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $spec->id,
            'capacity' => 30,
            'state' => Section::StatePlanned,
            'confirmed_at' => now(),
        ]);
    }
}
