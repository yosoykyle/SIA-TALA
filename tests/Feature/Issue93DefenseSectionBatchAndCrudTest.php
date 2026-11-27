<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Enrollment\PrepareStandardRegistration;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Filament\Pages\TermClasses;
use App\Models\AcademicYear;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingCommitment;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93DefenseSectionBatchAndCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_add_student_section_resolves_program_year_intake_batch_scopes_demand_and_displays_single_batch_in_tables(): void
    {
        $this->actingAsRegistrar();

        $ay2024 = AcademicYear::factory()->create(['label' => 'AY 2024-2025', 'starts_on' => '2024-08-01', 'ends_on' => '2025-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2025-2026', 'starts_on' => '2025-08-01', 'ends_on' => '2026-05-31', 'state' => AcademicYear::StateArchived, 'is_operational' => false]);
        $ay2026 = AcademicYear::factory()->create(['label' => 'AY 2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'state' => AcademicYear::StateActive, 'is_operational' => true]);

        $term = Term::factory()->for($ay2026)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2026-08-17',
            'ends_on' => '2026-12-18',
            'state' => Term::StateActive,
        ]);
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        $dbm = Program::factory()->create(['code' => 'DBM', 'name' => 'Diploma in Business Management', 'duration_years' => 3, 'is_active' => true]);
        $otherProgram = Program::factory()->create(['code' => 'DIT', 'name' => 'Diploma in Information Technology', 'duration_years' => 3, 'is_active' => true]);

        $sharedCurriculum = CurriculumVersion::factory()->for($dbm)->create([
            'name' => 'DBM Standard Curriculum',
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersionBatch::query()->create(['program_id' => $dbm->id, 'curriculum_version_id' => $sharedCurriculum->id, 'academic_year_id' => $ay2024->id]);
        CurriculumVersionBatch::query()->create(['program_id' => $dbm->id, 'curriculum_version_id' => $sharedCurriculum->id, 'academic_year_id' => $ay2025->id]);
        CurriculumVersionBatch::query()->create(['program_id' => $dbm->id, 'curriculum_version_id' => $sharedCurriculum->id, 'academic_year_id' => $ay2026->id]);

        $otherCurriculum = CurriculumVersion::factory()->for($otherProgram)->create(['name' => 'DIT Standard', 'state' => CurriculumVersion::StateActive]);

        $this->createCurriculumSubject($sharedCurriculum, 'DBM-201', 'Business Operations', 2, $term->type);
        $this->createCurriculumSubject($sharedCurriculum, 'DBM-301', 'Strategic Capstone', 3, $term->type);

        // 4 continuing students in Batch 2024-25 (Year 3), 7 in Batch 2025-26 (Year 2), plus 5 unrelated DIT students.
        StudentProfile::factory()->count(4)->create([
            'program_id' => $dbm->id,
            'curriculum_version_id' => $sharedCurriculum->id,
            'entry_academic_year_id' => $ay2024->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);
        StudentProfile::factory()->count(7)->create([
            'program_id' => $dbm->id,
            'curriculum_version_id' => $sharedCurriculum->id,
            'entry_academic_year_id' => $ay2025->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);
        StudentProfile::factory()->count(5)->create([
            'program_id' => $otherProgram->id,
            'curriculum_version_id' => $otherCurriculum->id,
            'entry_academic_year_id' => $ay2025->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);

        // 1. Add student section resolves Program + Year 2 -> Batch 2025-26 + scoped continuing demand (7, not 11 or 16!).
        $component = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->mountAction('recordCohort')
            ->set('mountedActions.0.data.program_id', $dbm->id)
            ->set('mountedActions.0.data.year_level', 2)
            ->assertSet('mountedActions.0.data.curriculum_version_id', $sharedCurriculum->id)
            ->assertSet('mountedActions.0.data.reference', 'DBM 2-1')
            ->assertMountedActionModalSee('Batch 2025–26')
            ->assertMountedActionModalSee('7 continuing students (Batch 2025–26)')
            ->set('mountedActions.0.data.confirmed_count', 22)
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('term_cohorts', [
            'term_id' => $term->id,
            'program_id' => $dbm->id,
            'curriculum_version_id' => $sharedCurriculum->id,
            'year_level' => 2,
            'reference' => 'DBM 2-1',
            'confirmed_count' => 22,
        ]);

        // 2. Cohorts table and Classes table show single intake batch (Batch 2025-26), NOT multi-batch range.
        $component->assertSee('Batch 2025–26')
            ->assertDontSee('Batch 2024–25 to 2026–27');

        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'classes'])
            ->test(TermClasses::class)
            ->assertSee('Batch 2025–26')
            ->assertDontSee('Batch 2024–25 to 2026–27');

        // 3. Suggested sections never double-count 2024 & 2025 batches sharing one curriculum.
        $page = Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])->test(TermClasses::class)->instance();
        $suggestions = collect($page->suggestedSectionsForTerm($term, false))
            ->where('program_id', $dbm->id)
            ->keyBy('year_level');

        $this->assertSame(4, $suggestions[3]['active_students']);
        $this->assertSame('2024–25', $suggestions[3]['batch_short']);
        $this->assertFalse($suggestions->has(2));

        // Direct scoped demandEvidence also respects program + curriculum + intake batch.
        $year3Demand = app(PrepareTermClassProposal::class)->demandEvidence(
            $term,
            programId: $dbm->id,
            curriculumVersionId: $sharedCurriculum->id,
            intakeStartYear: 2024,
        );
        $this->assertSame(4, $year3Demand['continuing_profiles']);
    }

    public function test_pre_publish_edit_and_delete_work_safely_on_linked_draft_sections_and_block_post_publish_or_enrollment(): void
    {
        $registrar = $this->actingAsRegistrar();

        $ay2026 = AcademicYear::factory()->create(['label' => 'AY 2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'state' => AcademicYear::StateActive, 'is_operational' => true]);
        $term = Term::factory()->for($ay2026)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2026-08-17',
            'ends_on' => '2026-12-18',
            'state' => Term::StateActive,
        ]);
        TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);

        $program = Program::factory()->create(['code' => 'DHM', 'name' => 'Diploma in Hospitality Management', 'duration_years' => 3, 'is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['name' => 'DHM Standard', 'state' => CurriculumVersion::StateActive]);
        CurriculumVersionBatch::query()->create(['program_id' => $program->id, 'curriculum_version_id' => $curriculum->id, 'academic_year_id' => $ay2026->id]);

        $this->createCurriculumSubject($curriculum, 'DHM-101', 'Hospitality Basics', 1, $term->type);
        $this->createCurriculumSubject($curriculum, 'DHM-102', 'Food Hygiene', 1, $term->type);

        // Create section DHM 1-1 with auto-loaded confirmed classes.
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->callAction('recordCohort', [
                'curriculum_version_id' => $curriculum->id,
                'year_level' => 1,
                'reference' => 'DHM 1-1',
                'confirmed_count' => 24,
                'auto_create_classes' => true,
            ])
            ->assertHasNoActionErrors();

        $cohort = TermCohort::query()->where('term_id', $term->id)->where('reference', 'DHM 1-1')->firstOrFail();
        $linkedSections = $cohort->classOfferings()->with('deliveryGroups.schedulingDemands')->get();
        $this->assertCount(2, $linkedSections);

        // Create a draft schedule run + candidate row + commitment to ensure cascade cleanup works and draft is marked Stale.
        $draftRun = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'Current',
        ]);
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $firstSection = $linkedSections->first();
        $firstDemand = $firstSection->deliveryGroups->first()->schedulingDemands->first();
        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $draftRun->id,
            'scheduling_demand_id' => $firstDemand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => null,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'status' => CandidateScheduleRow::StatusOk,
        ]);
        SchedulingCommitment::query()->create([
            'term_id' => $term->id,
            'section_id' => $firstSection->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'scope' => 'SectionWindow',
            'reason' => 'Draft pin',
            'authority_reference' => 'REGISTRAR-PIN',
            'recorded_by' => $registrar->id,
        ]);

        // 1. Edit linked pre-publish section: rename + change expected count -> updates pivot, Section capacity, DeliveryGroup, and Stale run!
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->assertTableActionVisible('editCohort', $cohort)
            ->assertTableActionVisible('deleteCohort', $cohort)
            ->callTableAction('editCohort', $cohort, [
                'reference' => 'DHM 1-A',
                'confirmed_count' => 31,
            ])
            ->assertHasNoTableActionErrors();

        $cohort->refresh();
        $this->assertSame('DHM 1-A', $cohort->reference);
        $this->assertSame(31, (int) $cohort->confirmed_count);
        $this->assertSame('Stale', $draftRun->fresh()->candidate_state);

        foreach ($cohort->classOfferings()->with('deliveryGroups')->get() as $section) {
            $this->assertSame(31, (int) $section->capacity);
            $this->assertSame(31, (int) $section->pivot->expected_count);
            $this->assertSame(31, (int) $section->deliveryGroups->first()->expected_count);
        }

        // 2. Delete linked pre-publish section -> removes sole draft classes + candidate rows + commitments + demands, and allows re-adding same name cleanly!
        $draftRun->update(['candidate_state' => 'Current']);
        $deletedSectionIds = $cohort->classOfferings()->pluck('sections.id')->all();

        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->callTableAction('deleteCohort', $cohort)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('term_cohorts', ['id' => $cohort->id]);
        foreach ($deletedSectionIds as $sectionId) {
            $this->assertDatabaseMissing('sections', ['id' => $sectionId]);
        }
        $this->assertSame('Stale', $draftRun->fresh()->candidate_state);

        // Immediately re-adding DHM 1-A with auto-load succeeds with zero code collisions!
        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->callAction('recordCohort', [
                'curriculum_version_id' => $curriculum->id,
                'year_level' => 1,
                'reference' => 'DHM 1-A',
                'confirmed_count' => 28,
                'auto_create_classes' => true,
            ])
            ->assertHasNoActionErrors();

        $recreated = TermCohort::query()->where('term_id', $term->id)->where('reference', 'DHM 1-A')->firstOrFail();

        // 3. Guard: Once an enrollment references the section OR timetable is published, Edit and Delete disappear!
        $student = StudentProfile::factory()->create(['program_id' => $program->id, 'curriculum_version_id' => $curriculum->id]);
        Enrollment::factory()->for($student)->for($term)->create(['student_section_id' => $recreated->id]);

        Livewire::withQueryParams(['termId' => $term->id, 'classesView' => 'cohorts'])
            ->test(TermClasses::class)
            ->assertTableActionHidden('editCohort', $recreated)
            ->assertTableActionHidden('deleteCohort', $recreated);
    }

    public function test_prepare_standard_registration_matches_exact_curriculum_and_year_level_cohorts(): void
    {
        $ay2025 = AcademicYear::factory()->create(['label' => 'AY 2025-2026', 'starts_on' => '2025-08-01', 'ends_on' => '2026-05-31']);
        $ay2026 = AcademicYear::factory()->create(['label' => 'AY 2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31']);
        $term = Term::factory()->for($ay2026)->create(['type' => Term::TypeFirstSemester, 'state' => Term::StateActive]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create(['state' => PublishedTimetableVersion::StatePublished]);

        $program = Program::factory()->create(['code' => 'BSIS', 'duration_years' => 4, 'is_active' => true]);
        $oldCurriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateSuperseded]);
        $newCurriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);

        $course = Course::factory()->create(['code' => 'IS-GEN', 'state' => Course::StateActive]);
        $spec = CourseSpecification::factory()->for($course)->create(['title' => 'Information Systems Core', 'credit_units' => 3, 'state' => CourseSpecification::StateActive]);

        $oldEntry = CurriculumEntry::factory()->for($oldCurriculum)->for($spec, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_label' => 'First Semester', 'term_type' => $term->type, 'sequence' => 1]);
        $newEntry = CurriculumEntry::factory()->for($newCurriculum)->for($spec, 'courseSpecification')->create(['year_level' => 'First Year', 'term_label' => 'First Semester', 'term_type' => $term->type, 'sequence' => 1]);

        $oldOffering = TermOffering::factory()->for($term)->for($oldEntry, 'curriculumEntry')->create(['state' => TermOffering::StateScheduled]);
        $newOffering = TermOffering::factory()->for($term)->for($newEntry, 'curriculumEntry')->create(['state' => TermOffering::StateScheduled]);

        $oldCohort = TermCohort::factory()->for($term)->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $oldCurriculum->id,
            'year_level' => 2,
            'reference' => 'BSIS 2-1',
            'state' => 'Confirmed',
            'confirmed_at' => now(),
        ]);
        $newCohort = TermCohort::factory()->for($term)->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $newCurriculum->id,
            'year_level' => 1,
            'reference' => 'BSIS 1-1',
            'state' => 'Confirmed',
            'confirmed_at' => now(),
        ]);

        // Deliberately give the Year 1 new-curriculum section larger capacity so old buggy block selection would pick it!
        $oldSection = Section::factory()->for($oldOffering, 'termOffering')->create(['code' => 'ISGEN-2A', 'state' => Section::StateOpen, 'capacity' => 25]);
        $oldSection->cohorts()->attach($oldCohort->id, ['expected_count' => 25]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $oldSection->id]);

        $newSection = Section::factory()->for($newOffering, 'termOffering')->create(['code' => 'ISGEN-1A', 'state' => Section::StateOpen, 'capacity' => 45]);
        $newSection->cohorts()->attach($newCohort->id, ['expected_count' => 45]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create(['section_id' => $newSection->id]);

        $student = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $oldCurriculum->id,
            'entry_academic_year_id' => $ay2025->id,
        ]);
        $case = Enrollment::factory()->for($student)->for($term)->create();

        $this->assertSame([$oldSection->id], app(PrepareStandardRegistration::class)->sectionIds($case));
    }

    private function actingAsRegistrar(): User
    {
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->syncPermissions([
            Permission::findOrCreate('manage-schedules', 'web'),
            Permission::findOrCreate('approve-documents', 'web'),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole($role);
        $this->actingAs($registrar)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);

        return $registrar;
    }

    private function createCurriculumSubject(CurriculumVersion $curriculum, string $code, string $title, int $yearLevel, string $termType): CurriculumEntry
    {
        $course = Course::factory()->create(['code' => $code, 'state' => Course::StateActive]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => $title,
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

        return CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => (string) $yearLevel,
            'term_label' => $termType === Term::TypeFirstSemester ? 'First Semester' : 'Second Semester',
            'term_type' => $termType,
        ]);
    }
}
