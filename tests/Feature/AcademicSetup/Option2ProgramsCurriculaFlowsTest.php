<?php

namespace Tests\Feature\AcademicSetup;

use App\Actions\Academics\AcademicAverageReadiness;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\AcademicSetup\CourseSpecificationLifecycleService;
use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Actions\Scheduling\RecordTermCohort;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\TermCalendar;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Pages\ViewCourse;
use App\Filament\Resources\Courses\Schemas\CourseInfolist;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\Programs\Pages\ViewProgram;
use App\Filament\Resources\Programs\Tables\ProgramsTable;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\ExternalCompetencyRequirement;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentIcon;
use Filament\View\PanelsIconAlias;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Option2ProgramsCurriculaFlowsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_batch_ownership_gap_range_labels_add_remove_batch_and_retired_continuing_cohorts_in_term_planning(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $lifecycle = app(CurriculumVersionLifecycleService::class);

        $program = Program::factory()->create([
            'code' => 'BSIT-OPT2',
            'name' => 'BS Information Technology (Option 2)',
            'duration_years' => 4,
            'is_active' => true,
        ]);

        $ay2027 = AcademicYear::factory()->create(['label' => '2027-2028', 'starts_on' => '2027-08-01', 'ends_on' => '2028-05-31']);
        $ay2028 = AcademicYear::factory()->create(['label' => '2028-2029', 'starts_on' => '2028-08-01', 'ends_on' => '2029-05-31']);
        $ay2029 = AcademicYear::factory()->create(['label' => '2029-2030', 'starts_on' => '2029-08-01', 'ends_on' => '2030-05-31']);
        $ay2030 = AcademicYear::factory()->create(['label' => '2030-2031', 'starts_on' => '2030-08-01', 'ends_on' => '2031-05-31']);
        $ay2032 = AcademicYear::factory()->create(['label' => '2032-2033', 'starts_on' => '2032-08-01', 'ends_on' => '2033-05-31']);

        $term2027 = Term::factory()->for($ay2027)->create([
            'type' => Term::TypeFirstSemester,
            'label' => '1st Semester 2027-2028',
            'state' => Term::StateActive,
        ]);
        TermCalendarPackage::factory()->for($term2027)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        // Create Draft starting with Batch 2027–28, add a ready subject, and activate.
        $draft = $lifecycle->createDraft(
            actor: $registrar,
            program: $program,
            versionCode: 'BSIT-2027',
            startingBatch: $ay2027,
        );

        $entry = app(CurriculumWorkbenchService::class)->quickPlaceSubject($registrar, $draft, [
            'code' => 'IT101-OPT2',
            'title' => 'Computing Fundamentals',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);

        $curriculum = $lifecycle->activate($registrar, $draft);
        $this->assertSame(CurriculumVersion::StateActive, $curriculum->state);
        $this->assertSame('Batch 2027–28', $curriculum->fresh()->batchLabel());

        // 1:1 batch ownership per program: another draft cannot claim Batch 2027–28.
        try {
            $lifecycle->createDraft(
                actor: $registrar,
                program: $program,
                versionCode: 'BSIT-DUP',
                startingBatch: $ay2027,
            );
            $this->fail('Expected 1:1 batch ownership validation error for already-assigned batch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('academic_year_id', $e->errors());
        }

        // Add contiguous batches 2028–29 & 2029–30, skip 2030–31 & 2031–32, and add gap batch 2032–33.
        $lifecycle->addBatch($registrar, $curriculum, $ay2028);
        $lifecycle->addBatch($registrar, $curriculum, $ay2029);
        $lifecycle->addBatch($registrar, $curriculum, $ay2032);

        $curriculum->refresh()->load('batches.academicYear');
        $this->assertSame('Batch 2027–28 to 2029–30, 2032–33', $curriculum->batchLabel());
        $this->assertSame('Batch 2027–28', $curriculum->startBatchLabel());
        $this->assertSame('Batch 2032–33', $curriculum->lastBatchLabel());

        // removeBatch succeeds when no student, cohort, or applicant uses Batch 2032–33.
        $lifecycle->removeBatch($registrar, $curriculum, $ay2032);
        $this->assertSame('Batch 2027–28 to 2029–30', $curriculum->fresh()->batchLabel());

        // removeBatch is blocked when a student profile or term cohort exists for Batch 2027–28.
        StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'entry_term_id' => $term2027->id,
        ]);

        try {
            $lifecycle->removeBatch($registrar, $curriculum, $ay2027);
            $this->fail('Expected removeBatch to block when a student belongs to that batch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('academic_year_id', $e->errors());
        }

        // Retire the curriculum (ACTIVE -> SUPERSEDED): no new batches allowed, but continuing cohorts remain valid in Term Planning.
        $retired = $lifecycle->retire($registrar, $curriculum);
        $this->assertTrue($retired->isRetired());
        $this->assertSame(CurriculumVersion::StateSuperseded, $retired->state);

        try {
            $lifecycle->addBatch($registrar, $retired, $ay2030);
            $this->fail('Expected retired curriculum to reject new batch assignments.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('curriculum_version', $e->errors());
        }

        // Continuing cohort on the retired curriculum can still be recorded and proposed in Term Planning.
        $cohort = app(RecordTermCohort::class)->execute($term2027, $registrar, [
            'curriculum_version_id' => $retired->id,
            'reference' => 'BSIT-2A-CONT',
            'year_level' => 2,
            'confirmed_count' => 35,
        ]);
        $this->assertSame($retired->id, $cohort->curriculum_version_id);
        $this->assertSame(2, $cohort->resolvedYearLevel());

        $preview = app(PrepareTermClassProposal::class)->preview($term2027);
        $catalogEntryIds = array_column($preview['catalog'], 'id');
        $this->assertContains($entry->id, $catalogEntryIds);

        $confirmed = app(PrepareTermClassProposal::class)->confirm($term2027, $registrar, [
            [
                'section_id' => null,
                'entry_id' => $entry->id,
                'action' => 'confirm',
                'code' => 'IT101-CONT-A',
                'capacity' => 40,
                'source' => Section::SourceRegular,
                'modality' => CourseSpecification::ModalityFaceToFace,
                'cohorts' => [['id' => $cohort->id, 'count' => 35]],
            ],
        ], $preview['token']);
        $this->assertSame(1, $confirmed['confirmed']);
    }

    public function test_copy_to_draft_forks_specification_on_edit_without_mutating_source_and_computes_diff(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $lifecycle = app(CurriculumVersionLifecycleService::class);
        $workbench = app(CurriculumWorkbenchService::class);

        $program = Program::factory()->create(['code' => 'BSCS-OPT2', 'duration_years' => 4, 'is_active' => true]);
        $ay2026 = AcademicYear::factory()->create(['label' => '2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31']);
        $ay2027 = AcademicYear::factory()->create(['label' => '2027-2028', 'starts_on' => '2027-08-01', 'ends_on' => '2028-05-31']);

        $sourceDraft = $lifecycle->createDraft($registrar, $program, 'BSCS-2026', startingBatch: $ay2026);

        $keptEntry = $workbench->quickPlaceSubject($registrar, $sourceDraft, [
            'code' => 'CS101-OPT2',
            'title' => 'Intro to Computer Science',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);
        $editedSourceEntry = $workbench->quickPlaceSubject($registrar, $sourceDraft, [
            'code' => 'CS102-OPT2',
            'title' => 'Data Structures',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeSecondSemester,
            'room_schedule_preset' => 'computer_lab',
        ]);
        $removedSourceEntry = $workbench->quickPlaceSubject($registrar, $sourceDraft, [
            'code' => 'CS103-OPT2',
            'title' => 'Legacy Assembly',
            'credit_units' => 3,
            'year_level' => 2,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);

        $sourceActive = $lifecycle->activate($registrar, $sourceDraft);
        $originalSpecId = $editedSourceEntry->fresh()->course_specification_id;

        // Copy to a new Draft for Batch 2027–28.
        $copiedDraft = $lifecycle->copyToDraft(
            actor: $registrar,
            source: $sourceActive,
            versionCode: 'BSCS-2027',
            startingBatch: $ay2027,
        );

        $this->assertSame($sourceActive->id, $copiedDraft->copied_from_curriculum_version_id);
        $this->assertCount(3, $copiedDraft->entries);

        // Edit CS102-OPT2 in the copied draft -> forks into a new Draft CourseSpecification without mutating the source curriculum.
        $copiedCs102Entry = $copiedDraft->entries()
            ->where('course_specification_id', $originalSpecId)
            ->sole();

        $forkedSpec = $workbench->updateSpecification($registrar, $copiedCs102Entry, [
            'title' => 'Advanced Data Structures & Algorithms',
            'credit_units' => 4,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'same_faculty_default' => true,
            'components' => [
                [
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 2,
                    'meeting_pattern' => '1x120',
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                    'requires_consecutive_block' => false,
                    'same_faculty' => true,
                    'sequence' => 1,
                ],
                [
                    'component_type' => CourseComponent::TypeLaboratory,
                    'weekly_contact_hours' => 2,
                    'meeting_pattern' => '1x120',
                    'room_type_default' => CourseComponent::RoomTypeComputerLaboratory,
                    'requires_consecutive_block' => false,
                    'same_faculty' => true,
                    'sequence' => 2,
                ],
            ],
        ]);

        $this->assertNotSame($originalSpecId, $forkedSpec->id);
        $this->assertSame($forkedSpec->id, $copiedCs102Entry->fresh()->course_specification_id);
        $this->assertSame($originalSpecId, $editedSourceEntry->fresh()->course_specification_id);
        $this->assertSame('Data Structures', $editedSourceEntry->fresh()->courseSpecification->title);
        $this->assertEquals(3.0, (float) $editedSourceEntry->fresh()->courseSpecification->credit_units);

        // Remove CS103-OPT2 and add a brand-new subject CS201-OPT2 in the copied draft.
        $copiedCs103Entry = $copiedDraft->entries()
            ->where('course_specification_id', $removedSourceEntry->course_specification_id)
            ->sole();
        $workbench->removeEntry($registrar, $copiedCs103Entry);

        $addedEntry = $workbench->quickPlaceSubject($registrar, $copiedDraft, [
            'code' => 'CS201-OPT2',
            'title' => 'AI Engineering Foundations',
            'credit_units' => 3,
            'year_level' => 2,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'computer_lab',
        ]);

        // Verify diffEntriesAgainstSource reports 1 Copied, 1 Edited, 1 Added, 1 Removed.
        $diff = $workbench->diffEntriesAgainstSource($copiedDraft->fresh());
        $this->assertTrue($diff['has_source']);
        $this->assertSame($sourceActive->id, $diff['source_id']);
        $this->assertSame([
            'copied' => 1,
            'edited' => 1,
            'added' => 1,
            'removed' => 1,
        ], $diff['counts']);
        $this->assertSame('Edited', $diff['entry_statuses'][$copiedCs102Entry->id]);
        $this->assertSame('Added', $diff['entry_statuses'][$addedEntry->id]);
        $this->assertSame('CS103-OPT2', $diff['removed'][0]['code']);
    }

    public function test_inline_quick_place_subject_handles_five_unit_recurring_computer_lab_ojt_and_code_lookup(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $workbench = app(CurriculumWorkbenchService::class);
        $lifecycle = app(CurriculumVersionLifecycleService::class);

        $program = Program::factory()->create(['code' => 'BSIS-OPT2', 'duration_years' => 4, 'is_active' => true]);
        $draft = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);

        // 1. 5-unit recurring lecture subject uses valid 2x150 meeting pattern and passes readiness.
        $fiveUnitEntry = $workbench->quickPlaceSubject($registrar, $draft, [
            'code' => 'MATH500',
            'title' => 'Differential & Integral Calculus',
            'credit_units' => 5,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);
        $fiveUnitSpec = $fiveUnitEntry->courseSpecification;
        $this->assertSame(CourseSpecification::SchedulingRecurring, $fiveUnitSpec->scheduling_treatment);
        $this->assertSame('2x150', $fiveUnitSpec->components->sole()->meeting_pattern);
        $this->assertEquals(5.0, (float) $fiveUnitSpec->components->sole()->weekly_contact_hours);

        // 2. computer_lab preset with prerequisite sets COMPUTER_LABORATORY room type and links prerequisite.
        $comLabEntry = $workbench->quickPlaceSubject($registrar, $draft, [
            'code' => 'IS202',
            'title' => 'Enterprise Database Systems',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeSecondSemester,
            'room_schedule_preset' => 'computer_lab',
            'prerequisite_course_id' => $fiveUnitSpec->course_id,
        ]);
        $comLabSpec = $comLabEntry->courseSpecification;
        $this->assertSame(CourseComponent::RoomTypeComputerLaboratory, $comLabSpec->components->sole()->room_type_default);
        $this->assertSame([$fiveUnitSpec->course_id], $comLabSpec->requirements->pluck('related_course_id')->all());

        // 3. ojt preset creates EXTERNALLY_ARRANGED subject with 0 weekly components.
        $ojtEntry = $workbench->quickPlaceSubject($registrar, $draft, [
            'code' => 'OJT400',
            'title' => 'Industry Internship / Practicum',
            'credit_units' => 6,
            'year_level' => 4,
            'term_type' => Term::TypeSecondSemester,
            'room_schedule_preset' => 'ojt',
        ]);
        $ojtSpec = $ojtEntry->courseSpecification;
        $this->assertSame(CourseSpecification::SchedulingExternallyArranged, $ojtSpec->scheduling_treatment);
        $this->assertCount(0, $ojtSpec->components);

        // Entire draft passes readiness with 0 errors.
        $this->assertSame([], $lifecycle->readinessErrors($draft->fresh()));

        // 4. lookupSubjectDefaultsByCode auto-fills code, title, units, preset, and prerequisite.
        $lookup = $workbench->lookupSubjectDefaultsByCode('is 202');
        $this->assertNotNull($lookup);
        $this->assertSame('IS202', $lookup['code']);
        $this->assertSame('Enterprise Database Systems', $lookup['title']);
        $this->assertSame(3.0, $lookup['credit_units']);
        $this->assertSame('computer_lab', $lookup['room_schedule_preset']);
        $this->assertSame($fiveUnitSpec->course_id, $lookup['prerequisite_course_id']);
    }

    public function test_admissions_availability_gate_applicant_inactive_program_filter_deactivation_guard_and_activate_and_proceed(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $lifecycle = app(CurriculumVersionLifecycleService::class);
        $workbench = app(CurriculumWorkbenchService::class);

        $ay2027 = AcademicYear::factory()->create([
            'label' => '2027-2028',
            'state' => AcademicYear::StateActive,
            'starts_on' => '2027-08-01',
            'ends_on' => '2028-05-31',
        ]);
        $term2027 = Term::factory()->for($ay2027)->create([
            'state' => Term::StateActive,
            'starts_on' => '2027-08-01',
            'ends_on' => '2027-12-15',
        ]);
        $openCycle = AdmissionCycle::factory()->published()->for($term2027)->create([
            'code' => 'ADM-2027-1',
            'label' => 'AY 2027-2028 1st Semester Admissions',
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDays(14),
            'correction_closes_at' => now()->addDays(21),
        ]);

        $programReady = Program::factory()->create(['code' => 'BSIT-ADM', 'name' => 'Diploma in Information Technology', 'is_active' => true]);
        $programNoBatch = Program::factory()->create(['code' => 'BSBA-ADM', 'name' => 'Diploma in Business Administration', 'is_active' => true]);
        $programInactive = Program::factory()->create(['code' => 'BSED-ADM', 'name' => 'Diploma in Secondary Education', 'is_active' => false]);

        foreach ([$programReady, $programNoBatch, $programInactive] as $prog) {
            $openCycle->programs()->attach($prog->id, [
                'accepts_first_year' => true,
                'accepts_transferee' => true,
            ]);
        }

        // Assign published curriculum for Batch 2027–28 only to $programReady.
        $draft = $lifecycle->createDraft($registrar, $programReady, 'BSIT-ADM-2027', startingBatch: $ay2027);
        $workbench->quickPlaceSubject($registrar, $draft, [
            'code' => 'IT100-ADM',
            'title' => 'Intro to IT',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);
        $lifecycle->activate($registrar, $draft);

        // 1. 2-condition Program::admissionsAvailability()
        $availReady = $programReady->admissionsAvailability($openCycle);
        $this->assertTrue($availReady['available']);
        $this->assertSame('Available in Admissions', $availReady['reason']);

        $availNoBatch = $programNoBatch->admissionsAvailability($openCycle);
        $this->assertFalse($availNoBatch['available']);
        $this->assertStringContainsString('Not available in Admissions: no curriculum for Batch 2027–28', $availNoBatch['reason']);

        $availInactive = $programInactive->admissionsAvailability($openCycle);
        $this->assertFalse($availInactive['available']);
        $this->assertSame('Program is inactive', $availInactive['reason']);

        // 2. Application::officialProgramOptions() blocks inactive programs even when attached to $openCycle ($cycleId >= 1).
        $applicationPage = new class extends Application
        {
            public function exposeOfficialOptions(int $cycleId, string $path): array
            {
                return $this->officialProgramOptions($cycleId, $path);
            }
        };

        $cycleOptions = $applicationPage->exposeOfficialOptions($openCycle->id, AdmissionApplication::PathFirstYear);
        $this->assertArrayHasKey($programReady->id, $cycleOptions);
        $this->assertArrayNotHasKey($programInactive->id, $cycleOptions);

        // 3. Refined Deactivation Guard: allowed when attached to open cycle with 0 applicants and another active program exists;
        // blocked when an unresolved applicant exists in the open cycle.
        $this->assertNull(ProgramsTable::deactivationBlockReason($programReady));

        AdmissionApplication::factory()->create([
            'admission_cycle_id' => $openCycle->id,
            'term_id' => $term2027->id,
            'program_id' => $programReady->id,
            'application_state' => AdmissionApplication::StateSubmitted,
        ]);
        $this->assertStringContainsString(
            'Cannot deactivate BSIT-ADM while 1 active applicant is in an open admission cycle',
            (string) ProgramsTable::deactivationBlockReason($programReady),
        );

        // 4. Activate and proceed on inactive program when creating a batch curriculum.
        try {
            $lifecycle->createDraft(
                actor: $registrar,
                program: $programInactive,
                versionCode: 'BSED-2027',
                startingBatch: $ay2027,
                activateProgramIfInactive: false,
            );
            $this->fail('Expected inactive program without confirmation to block batch curriculum creation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('program_id', $e->errors());
        }

        $activatedDraft = $lifecycle->createDraft(
            actor: $registrar,
            program: $programInactive,
            versionCode: 'BSED-2027',
            startingBatch: $ay2027,
            activateProgramIfInactive: true,
        );
        $this->assertTrue($programInactive->fresh()->is_active);
        $this->assertSame('Batch 2027–28', $activatedDraft->batchLabel());
    }

    public function test_livewire_smoke_renders_for_workbench_view_program_tabs_review_wizard_and_term_calendar_fixes(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $lifecycle = app(CurriculumVersionLifecycleService::class);
        $workbench = app(CurriculumWorkbenchService::class);

        $ay = AcademicYear::factory()->create([
            'label' => '2027-2028',
            'state' => AcademicYear::StateActive,
            'starts_on' => '2027-08-01',
            'ends_on' => '2028-05-31',
        ]);
        $term = Term::factory()->for($ay)->create([
            'type' => Term::TypeFirstSemester,
            'label' => '1st Semester 2027-2028',
            'state' => Term::StateActive,
            'starts_on' => '2027-08-10',
            'ends_on' => '2027-12-15',
        ]);

        $program = Program::factory()->create([
            'code' => 'BSIT-SMK',
            'name' => 'BS Information Technology Smoke',
            'duration_years' => 4,
            'is_active' => true,
        ]);

        $draft = $lifecycle->createDraft($registrar, $program, 'BSIT-SMK-2027', startingBatch: $ay);
        $workbench->quickPlaceSubject($registrar, $draft, [
            'code' => 'IT101-SMK',
            'title' => 'Intro to Computing Smoke',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);
        $workbench->addExternalCompetencyRequirement($registrar, $draft, [
            'qualification_label' => 'Computer Systems Servicing NC II',
            'qualification_level' => 'NC II',
            'treatment' => ExternalCompetencyRequirement::TreatmentTrackedOnly,
        ]);

        // 1. CatalogCurriculaWorkbench renders unified Program Cards and Has drafts filter tab.
        Livewire::test(CatalogCurriculaWorkbench::class)
            ->assertOk()
            ->assertSee('BSIT-SMK')
            ->assertSee('BS Information Technology Smoke')
            ->assertSee('Has drafts')
            ->assertSee('1 draft')
            ->call('setStatusFilter', 'has_drafts')
            ->assertSee('BSIT-SMK');

        // 2. ViewProgram renders Tab 1 (curricula) and Tab 2 (sections).
        Livewire::test(ViewProgram::class, ['record' => $program->id])
            ->assertOk()
            ->assertSee('Curricula')
            ->assertSee('Year levels & sections')
            ->assertSee('Batch 2027–28')
            ->call('switchTab', 'sections')
            ->assertSet('activeTab', 'sections')
            ->assertSee('Year levels & sections');

        // 3. ReviewCurriculumVersion renders Year 1 without TESDA card, then shows TESDA card & summary on Review step.
        Livewire::test(ReviewCurriculumVersion::class, ['record' => $draft->getRouteKey()])
            ->assertOk()
            ->assertSet('activeYear', 1)
            ->assertSet('activeStep', 'year')
            ->assertSee('Batch 2027–28')
            ->assertSee('IT101-SMK')
            ->assertDontSee('TESDA & External Certifications')
            ->call('selectReviewStep')
            ->assertSet('activeStep', 'review')
            ->assertSee('TESDA & External Certifications')
            ->assertSee('Computer Systems Servicing NC II');

        // 4. TermCalendar renders linked Admissions Cycle banner, School events & no-class days, and 1-step Update activity dates.
        AdmissionCycle::factory()->published()->for($term)->create([
            'code' => 'ADM-SMK-2027',
            'label' => 'SY 2027-2028 First Semester Intake',
            'opens_at' => '2027-06-01 08:00:00',
            'closes_at' => '2027-07-31 17:00:00',
            'correction_closes_at' => '2027-08-05 17:00:00',
        ]);

        $package = TermCalendarPackage::factory()->for($term)->create([
            'version' => 1,
            'state' => TermCalendarPackage::StateActive,
            'administrative_starts_on' => '2027-08-10',
            'administrative_ends_on' => '2027-12-15',
            'classes_start_on' => '2027-08-10',
            'classes_end_on' => '2027-12-15',
            'activated_at' => now(),
        ]);
        TermTeachingGridRow::factory()->create([
            'term_calendar_package_id' => $package->id,
            'day_of_week' => 1,
            'starts_at' => '07:00:00',
            'ends_at' => '19:00:00',
            'breaks' => [],
        ]);
        $package->datedExceptions()->create([
            'starts_on' => '2027-08-30',
            'ends_on' => '2027-08-30',
            'exception_type' => 'Holiday',
            'label' => 'National Heroes Day',
            'authority_reference' => 'PROC-2027-01',
            'blocks_teaching' => true,
        ]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->assertOk()
            ->assertSee('SY 2027-2028 First Semester Intake')
            ->assertSee('Manage in Admissions')
            ->assertSee('School events & no-class days')
            ->mountAction('addOperationalDates')
            ->setActionData([
                'windows' => [
                    [
                        'window_type' => 'Enrollment',
                        'opens_on' => '2027-08-01',
                        'closes_on' => '2027-08-09',
                        'cutoff_at' => null,
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Activity dates updated');

        $this->assertSame(0, $term->calendarPackages()->where('state', TermCalendarPackage::StateDraft)->count());
        $latestActive = $term->calendarPackages()->where('state', TermCalendarPackage::StateActive)->sole();
        $this->assertNotSame($package->id, $latestActive->id);
        $this->assertCount(1, $latestActive->windows);
    }

    public function test_catalog_curricula_workbench_native_card_grid_paginates_at_nine_and_filters_by_search_status_and_term(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $ay = AcademicYear::factory()->create([
            'label' => '2027-2028',
            'state' => AcademicYear::StateActive,
            'starts_on' => '2027-08-01',
            'ends_on' => '2028-05-31',
        ]);
        $activeTerm = Term::factory()->for($ay)->create([
            'type' => Term::TypeFirstSemester,
            'label' => '1st Semester 2027-2028',
            'state' => Term::StateActive,
            'starts_on' => '2027-08-01',
            'ends_on' => '2027-12-15',
        ]);
        $secondTerm = Term::factory()->for($ay)->create([
            'type' => Term::TypeSecondSemester,
            'label' => '2nd Semester 2027-2028',
            'state' => Term::StateDraft,
            'starts_on' => '2028-01-10',
            'ends_on' => '2028-05-31',
        ]);

        $programs = collect(range(1, 13))->map(fn (int $index): Program => Program::factory()->create([
            'code' => sprintf('PRG-%02d', $index),
            'name' => sprintf('Program Card %02d', $index),
            'duration_years' => 4,
            'is_active' => true,
        ]));

        $firstProgram = $programs->first();
        $lastProgram = $programs->last();

        $activeCurriculum = CurriculumVersion::factory()->for($firstProgram)->create([
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersion::factory()->for($lastProgram)->create([
            'state' => CurriculumVersion::StateDraft,
        ]);

        app(RecordTermCohort::class)->execute($secondTerm, $registrar, [
            'curriculum_version_id' => $activeCurriculum->id,
            'reference' => 'PRG01-2A',
            'year_level' => 2,
            'confirmed_count' => 28,
        ]);

        Livewire::test(CatalogCurriculaWorkbench::class)
            ->assertOk()
            ->assertSet('selectedTermId', $activeTerm->id)
            ->assertDontSee('Active filters')
            ->assertCanSeeTableRecords($programs->take(12))
            ->assertCanNotSeeTableRecords($programs->slice(12))
            ->searchTable('PRG-13')
            ->assertCanSeeTableRecords([$lastProgram])
            ->assertCanNotSeeTableRecords($programs->take(12))
            ->searchTable('')
            ->filterTable('status', 'has_drafts')
            ->assertSet('statusFilter', 'has_drafts')
            ->assertCanSeeTableRecords([$lastProgram])
            ->assertCanNotSeeTableRecords($programs->take(12))
            ->filterTable('status', null)
            ->assertSet('statusFilter', 'all')
            ->filterTable('term_id', (string) $secondTerm->id)
            ->assertSet('selectedTermId', $secondTerm->id)
            ->assertSee('2nd Year');
    }

    public function test_courses_table_bulk_actions_assign_and_remove_subjects_on_draft_curricula_only_and_hide_for_academic_head(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create([
            'code' => 'BSIT-BLK',
            'name' => 'BS Information Technology Bulk',
            'duration_years' => 4,
            'is_active' => true,
        ]);

        $activeCurriculum = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateActive,
        ]);
        $draftCurriculum = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateDraft,
        ]);

        $courseA = Course::factory()->create(['code' => 'BLK101', 'state' => Course::StateActive]);
        $specA = CourseSpecification::factory()->for($courseA)->create([
            'title' => 'Bulk Subject Alpha',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);

        $courseB = Course::factory()->create(['code' => 'BLK102', 'state' => Course::StateActive]);
        $specB = CourseSpecification::factory()->for($courseB)->create([
            'title' => 'Bulk Subject Beta',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);

        CurriculumEntry::factory()->for($activeCurriculum)->for($specA, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);
        CurriculumEntry::factory()->for($draftCurriculum)->for($specA, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);

        Livewire::test(ListCourses::class)
            ->assertTableBulkActionVisible('assignSelectedToCurricula')
            ->assertTableBulkActionVisible('removeSelectedFromCurricula')
            ->assertTableBulkActionVisible('deleteSelectedSubjects')
            ->callTableBulkAction('assignSelectedToCurricula', [$courseA, $courseB], data: [
                'curriculum_version_ids' => [$draftCurriculum->id],
                'year_level' => 2,
                'term_type' => Term::TypeSecondSemester,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Assigned 1 subject to 1 draft curriculum (skipped 1 already present).');

        $this->assertSame(2, $draftCurriculum->entries()->count());
        $this->assertSame(1, $activeCurriculum->entries()->count());
        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $draftCurriculum->id,
            'course_specification_id' => $specB->id,
            'year_level' => '2',
            'term_type' => Term::TypeSecondSemester,
        ]);

        Livewire::test(ListCourses::class)
            ->callTableBulkAction('removeSelectedFromCurricula', [$courseA, $courseB], data: [
                'curriculum_version_ids' => [$draftCurriculum->id],
            ])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Removed 2 subject placements from 1 draft curriculum.');

        $this->assertSame(0, $draftCurriculum->entries()->count());
        $this->assertSame(1, $activeCurriculum->entries()->count());

        $unusedDraftCourse = Course::factory()->create(['code' => 'BLK103', 'state' => Course::StateActive]);
        CourseSpecification::factory()->for($unusedDraftCourse)->create([
            'title' => 'Unused Draft Subject',
            'credit_units' => 3,
            'state' => CourseSpecification::StateDraft,
        ]);

        // Bulk delete removes only unused unpublished draft subjects, skipping both referenced ($courseA) and published Active ($courseB) subjects.
        Livewire::test(ListCourses::class)
            ->callTableBulkAction('deleteSelectedSubjects', [$courseA, $courseB, $unusedDraftCourse])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Deleted 1 unused draft subject');

        $this->assertDatabaseMissing('courses', ['id' => $unusedDraftCourse->id]);
        $this->assertDatabaseHas('courses', ['id' => $courseA->id]);
        $this->assertDatabaseHas('courses', ['id' => $courseB->id]);

        Livewire::test(ListCourses::class)
            ->callTableBulkAction('deleteSelectedSubjects', [$courseA, $courseB])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('No subjects deleted');

        $this->assertDatabaseHas('courses', ['id' => $courseA->id]);
        $this->assertDatabaseHas('courses', ['id' => $courseB->id]);

        $this->staff(User::StaffRoleAcademicHead);

        Livewire::test(ListCourses::class)
            ->assertTableBulkActionHidden('assignSelectedToCurricula')
            ->assertTableBulkActionHidden('removeSelectedFromCurricula')
            ->assertTableBulkActionHidden('deleteSelectedSubjects');
    }

    public function test_subject_description_persists_on_create_and_updates_on_fork_without_mutating_source_revision(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $lifecycle = app(CurriculumVersionLifecycleService::class);
        $workbench = app(CurriculumWorkbenchService::class);

        $ay2027 = AcademicYear::factory()->create(['label' => '2027-2028', 'starts_on' => '2027-08-01', 'ends_on' => '2028-05-31']);
        $ay2028 = AcademicYear::factory()->create(['label' => '2028-2029', 'starts_on' => '2028-08-01', 'ends_on' => '2029-05-31']);
        $program = Program::factory()->create(['code' => 'BSIT-DESC', 'duration_years' => 4, 'is_active' => true]);

        $createdSpec = $workbench->createDraftSubject($registrar, [
            'code' => 'IT150-DESC',
            'title' => 'Systems Integration Basics',
            'description' => '  Covers API integration, messaging patterns, and service reliability.  ',
            'credit_units' => 3,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'same_faculty_default' => true,
            'components' => [
                [
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 3,
                    'meeting_pattern' => '2x90',
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                    'requires_consecutive_block' => false,
                    'same_faculty' => true,
                    'sequence' => 1,
                ],
            ],
        ]);

        $this->assertSame(
            'Covers API integration, messaging patterns, and service reliability.',
            $createdSpec->fresh()->description,
        );

        $sourceDraft = $lifecycle->createDraft($registrar, $program, 'BSIT-DESC-2027', startingBatch: $ay2027);
        $sourceEntry = CurriculumEntry::factory()->for($sourceDraft)->for($createdSpec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
        ]);
        $sourceActive = $lifecycle->activate($registrar, $sourceDraft);
        $sourceSpecId = $sourceEntry->fresh()->course_specification_id;

        $copiedDraft = $lifecycle->copyToDraft($registrar, $sourceActive, 'BSIT-DESC-2028', startingBatch: $ay2028);
        $copiedEntry = $copiedDraft->entries()->where('course_specification_id', $sourceSpecId)->sole();

        $forkedSpec = $workbench->updateSpecification($registrar, $copiedEntry, [
            'title' => 'Systems Integration & Cloud APIs',
            'description' => 'Updated syllabus adding cloud webhooks and observability.',
            'credit_units' => 3,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'same_faculty_default' => true,
            'components' => [
                [
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 3,
                    'meeting_pattern' => '2x90',
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                    'requires_consecutive_block' => false,
                    'same_faculty' => true,
                    'sequence' => 1,
                ],
            ],
        ]);

        $this->assertNotSame($sourceSpecId, $forkedSpec->id);
        $this->assertSame('Updated syllabus adding cloud webhooks and observability.', $forkedSpec->fresh()->description);
        $this->assertSame(
            'Covers API integration, messaging patterns, and service reliability.',
            CourseSpecification::query()->findOrFail($sourceSpecId)->description,
        );

        $updatedInPlace = $workbench->updateSpecification($registrar, $copiedEntry->fresh(), [
            'title' => 'Systems Integration & Cloud APIs',
            'description' => 'Refined draft description before activation.',
            'credit_units' => 3,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'same_faculty_default' => true,
            'components' => [
                [
                    'component_type' => CourseComponent::TypeLecture,
                    'weekly_contact_hours' => 3,
                    'meeting_pattern' => '2x90',
                    'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                    'requires_consecutive_block' => false,
                    'same_faculty' => true,
                    'sequence' => 1,
                ],
            ],
        ]);

        $this->assertSame($forkedSpec->id, $updatedInPlace->id);
        $this->assertSame('Refined draft description before activation.', $updatedInPlace->fresh()->description);
    }

    public function test_view_course_usage_filtering_status_labels_pagination_and_reset_filters(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $programA = Program::factory()->create(['code' => 'BSIT-USG', 'name' => 'BS Information Technology Usage', 'is_active' => true]);
        $programB = Program::factory()->create(['code' => 'BSCS-USG', 'name' => 'BS Computer Science Usage', 'is_active' => true]);

        $course = Course::factory()->create(['code' => 'USG101', 'state' => Course::StateActive]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => 'Applied Networking Fundamentals',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);

        $activeCurricula = collect(range(1, 9))->map(fn (int $index): CurriculumVersion => CurriculumVersion::factory()->for($programA)->create([
            'version_code' => sprintf('BSIT-ACT-%02d', $index),
            'name' => sprintf('IT Active Plan %02d', $index),
            'state' => CurriculumVersion::StateActive,
        ]));
        $draftCurriculum = CurriculumVersion::factory()->for($programB)->create([
            'version_code' => 'BSCS-DRF-01',
            'name' => 'CS Draft Cloud Track',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $retiredCurriculum = CurriculumVersion::factory()->for($programB)->create([
            'version_code' => 'BSCS-RET-01',
            'name' => 'CS Legacy Track 2022',
            'state' => CurriculumVersion::StateSuperseded,
        ]);
        $extraRetiredCurriculum = CurriculumVersion::factory()->for($programA)->create([
            'version_code' => 'BSIT-RET-02',
            'name' => 'IT Legacy Track 2021',
            'state' => CurriculumVersion::StateSuperseded,
        ]);

        foreach ([...$activeCurricula->all(), $draftCurriculum, $retiredCurriculum, $extraRetiredCurriculum] as $curriculum) {
            CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
                'year_level' => '1',
                'term_type' => Term::TypeFirstSemester,
            ]);
        }

        $component = Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->assertOk()
            ->assertSee('Showing 1–10 of 12 curricula')
            ->assertSee('Page 1 of 2')
            ->assertSee('Open curriculum')
            ->assertSee('CS Draft Cloud Track')
            ->assertSeeHtml('fi-color-warning')
            ->call('setUsagePage', 2)
            ->assertSee('Page 2 of 2')
            ->assertSee('Showing 11–12 of 12 curricula')
            ->assertSee('Retired')
            ->assertSee('CS Legacy Track 2022')
            ->set('usageProgramFilter', (string) $programB->id)
            ->assertSet('usagePage', 1)
            ->assertSee('Showing 1–2 of 2 curricula')
            ->assertSee('CS Draft Cloud Track')
            ->assertSee('CS Legacy Track 2022')
            ->assertDontSee('IT Active Plan 01')
            ->set('usageStatusFilter', CourseInfolist::UsageStatusDraft)
            ->assertSee('Showing 1–1 of 1 curriculum')
            ->assertSee('CS Draft Cloud Track')
            ->assertDontSee('CS Legacy Track 2022')
            ->set('usageStatusFilter', CourseInfolist::UsageStatusRetired)
            ->assertSee('Showing 1–1 of 1 curriculum')
            ->assertSee('Retired')
            ->assertSee('CS Legacy Track 2022')
            ->assertDontSee('CS Draft Cloud Track')
            ->set('usageProgramFilter', '')
            ->set('usageStatusFilter', '')
            ->set('usageSearch', 'Cloud Track')
            ->assertSee('Showing 1–1 of 1 curriculum')
            ->assertSee('CS Draft Cloud Track')
            ->assertDontSee('IT Active Plan 01')
            ->set('usageSearch', 'Nonexistent Curriculum Name')
            ->assertSee('No matching curricula')
            ->call('resetUsageFilters')
            ->assertSet('usageSearch', '')
            ->assertSet('usageProgramFilter', '')
            ->assertSet('usageStatusFilter', '')
            ->assertSet('usagePage', 1)
            ->assertSee('Showing 1–10 of 12 curricula')
            ->assertSee('IT Active Plan 01');

        $this->assertSame(12, $component->instance()->curriculumUsageData()['totalCount']);
    }

    public function test_view_program_native_tabs_direct_draft_delete_and_year_levels_sections_readiness(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $lifecycle = app(CurriculumVersionLifecycleService::class);
        $workbench = app(CurriculumWorkbenchService::class);

        $ay2026 = AcademicYear::factory()->create(['label' => '2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31']);
        $ay2027 = AcademicYear::factory()->create([
            'label' => '2027-2028',
            'state' => AcademicYear::StateActive,
            'starts_on' => '2027-08-01',
            'ends_on' => '2028-05-31',
        ]);
        $ay2028 = AcademicYear::factory()->create(['label' => '2028-2029', 'starts_on' => '2028-08-01', 'ends_on' => '2029-05-31']);

        $activeTerm = Term::factory()->for($ay2027)->create([
            'type' => Term::TypeFirstSemester,
            'label' => '1st Semester 2027-2028',
            'state' => Term::StateActive,
            'starts_on' => '2027-08-10',
            'ends_on' => '2027-12-15',
        ]);

        $program = Program::factory()
            ->withDescription('Trains software developers and infrastructure specialists.')
            ->create([
                'code' => 'BSIT-VW',
                'name' => 'BS Information Technology View',
                'duration_years' => 4,
                'is_active' => true,
            ]);

        $retiredDraft = $lifecycle->createDraft($registrar, $program, 'BSIT-VW-2026', startingBatch: $ay2026);
        $workbench->quickPlaceSubject($registrar, $retiredDraft, [
            'code' => 'VW100',
            'title' => 'Foundations Legacy',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);
        $retiredCurriculum = $lifecycle->retire($registrar, $lifecycle->activate($registrar, $retiredDraft));

        $activeDraft = $lifecycle->createDraft($registrar, $program, 'BSIT-VW-2027', startingBatch: $ay2027);
        $workbench->quickPlaceSubject($registrar, $activeDraft, [
            'code' => 'VW101',
            'title' => 'Modern Web Systems',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'computer_lab',
        ]);
        $activeCurriculum = $lifecycle->activate($registrar, $activeDraft);

        $draftCurriculum = $lifecycle->createDraft($registrar, $program, 'BSIT-VW-2028', startingBatch: $ay2028);

        // Planned cohort with 0 enrolled students to verify Term readiness summary & planned batch fallback.
        app(RecordTermCohort::class)->execute($activeTerm, $registrar, [
            'curriculum_version_id' => $activeCurriculum->id,
            'reference' => 'bsit-1a',
            'year_level' => 1,
            'confirmed_count' => 25,
        ]);

        $curriculaComponent = Livewire::test(ViewProgram::class, ['record' => $program->id])
            ->assertOk()
            ->assertSee('Trains software developers and infrastructure specialists.')
            ->assertSeeHtml('tala-program-tabs')
            ->assertSee('Batch 2027–28')
            ->assertSee('Batch 2028–29')
            ->assertSee('Batch 2026–27');

        $curriculaHtml = $curriculaComponent->html();

        $this->assertMatchesRegularExpression(
            '/wire:key="curriculum-row-'.$draftCurriculum->id.'".*?deleteDraftCurriculum.*?<\/tr>/s',
            $curriculaHtml,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/wire:key="curriculum-row-'.$draftCurriculum->id.'".*?More actions.*?<\/tr>/s',
            $curriculaHtml,
        );
        $this->assertMatchesRegularExpression(
            '/wire:key="curriculum-row-'.$activeCurriculum->id.'".*?More actions.*?<\/tr>/s',
            $curriculaHtml,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/wire:key="curriculum-row-'.$retiredCurriculum->id.'".*?More actions.*?<\/tr>/s',
            $curriculaHtml,
        );

        $curriculaComponent
            ->call('switchTab', 'sections')
            ->assertSet('activeTab', 'sections')
            ->set('selectedTermId', $activeTerm->id)
            ->assertSee('Term readiness summary')
            ->assertSee('1')
            ->assertSee('year level')
            ->assertSee('0 / 25')
            ->assertSee('class places filled')
            ->assertSee('0 / 1 scheduled')
            ->assertSee('Open Term Planning')
            ->assertSee('Batch 2027–28')
            ->assertSee('BSIT-1A')
            ->assertSee('No schedule yet')
            ->assertSee('Schedule');
    }

    public function test_tesda_competency_and_non_credit_gwa_semantics_auto_detection_sidebar_toggle_icons_and_subject_overview_redesign(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $workbench = app(CurriculumWorkbenchService::class);
        $averageReadiness = app(AcademicAverageReadiness::class);
        $projection = app(OfficialCourseResultProjection::class);

        // 1. Auto-detection of TESDA_COMPETENCY and NON_CREDIT via CurriculumWorkbenchService
        $program = Program::factory()->create(['code' => 'BSIT-P9', 'duration_years' => 4, 'is_active' => true]);
        $draft = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);

        $tesdaEntry = $workbench->quickPlaceSubject($registrar, $draft, [
            'code' => 'CSS201-P9',
            'title' => 'Computer Systems Servicing NC II',
            'description' => 'Hands-on hardware assembly, OS installation, and network configuration for NC II assessment.',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'computer_lab',
        ]);
        $nonCreditEntry = $workbench->quickPlaceSubject($registrar, $draft, [
            'code' => 'BRM001-P9',
            'title' => 'Bridging Mathematics',
            'description' => 'Foundational algebra and pre-calculus refresher for incoming students.',
            'credit_units' => 3,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);

        $tesdaSpec = $tesdaEntry->courseSpecification->fresh();
        $nonCreditSpec = $nonCreditEntry->courseSpecification->fresh();

        $this->assertSame(CourseSpecification::AcademicClassificationTesdaCompetency, $tesdaSpec->academic_classification);
        $this->assertSame(CourseSpecification::AcademicClassificationNonCredit, $nonCreditSpec->academic_classification);

        // OfficialCourseResultProjection & AcademicAverageReadiness: TESDA_COMPETENCY is included in GWA; NON_CREDIT is excluded.
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $student = StudentProfile::factory()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $draft->id,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'credential_user_id' => $student->user_id,
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);

        foreach ([
            [$tesdaEntry, '1.50'],
            [$nonCreditEntry, '3.00'],
        ] as [$entry, $grade]) {
            $offering = TermOffering::factory()->create([
                'term_id' => $term->id,
                'curriculum_entry_id' => $entry->id,
                'state' => TermOffering::StateScheduled,
            ]);
            $section = Section::factory()->create([
                'term_offering_id' => $offering->id,
                'state' => Section::StateOpen,
            ]);
            $courseEnrollment = CourseEnrollment::query()->create([
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $offering->id,
                'section_id' => $section->id,
                'status' => CourseEnrollment::StatusActive,
                'is_current' => true,
                'units_snapshot' => 3,
                'added_at' => now(),
            ]);
            $roster = GradeRoster::factory()->create([
                'section_id' => $section->id,
                'state' => GradeRoster::StateReleased,
            ]);
            $row = GradeRosterRow::factory()->create([
                'grade_roster_id' => $roster->id,
                'course_enrollment_id' => $courseEnrollment->id,
                'final_result' => $grade,
                'current_outcome_code' => $grade,
                'current_outcome_category' => GradeRosterRow::CategoryPassing,
                'released_at' => now(),
            ]);
            GradeOutcomeEvent::factory()->create([
                'grade_roster_row_id' => $row->id,
                'event_type' => GradeOutcomeEvent::TypeInitialRelease,
                'result_code' => $grade,
                'released_at' => now(),
            ]);
        }

        $projectedResults = $projection->forStudent($student, $term);
        $this->assertCount(2, $projectedResults);

        $tesdaProjected = $projectedResults->firstWhere('course_specification.id', $tesdaSpec->id);
        $nonCreditProjected = $projectedResults->firstWhere('course_specification.id', $nonCreditSpec->id);

        $this->assertNotNull($tesdaProjected);
        $this->assertNull($tesdaProjected['average_exclusion']);
        $this->assertNotNull($nonCreditProjected);
        $this->assertSame('BRM001-P9: Non-credit/bridging course excluded.', $nonCreditProjected['average_exclusion']);

        $this->assertSame(AcademicAverageReadiness::Available, $averageReadiness->forResults($projectedResults));
        $this->assertCount(1, $averageReadiness->includedResults($projectedResults));
        $this->assertSame('1.50', $averageReadiness->average($projectedResults));

        // 2. Global sidebar panel toggle icon registration
        $collapseIcon = FilamentIcon::resolve(PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON);
        $expandIcon = FilamentIcon::resolve(PanelsIconAlias::SIDEBAR_EXPAND_BUTTON);

        $this->assertInstanceOf(View::class, $collapseIcon);
        $this->assertInstanceOf(View::class, $expandIcon);
        $this->assertSame('filament.components.icons.sidebar-collapse', $collapseIcon->name());
        $this->assertSame('filament.components.icons.sidebar-expand', $expandIcon->name());

        // 3. ViewCourse renders redesigned Subject overview (spec strip, GWA inclusion/exclusion, TESDA badge, description lead without nested card)
        Livewire::test(ViewCourse::class, ['record' => $tesdaSpec->course_id])
            ->assertOk()
            ->assertSee('Subject overview')
            ->assertSee('TESDA Competency')
            ->assertSee('Hands-on hardware assembly, OS installation, and network configuration for NC II assessment.')
            ->assertSee('Included in GWA')
            ->assertSeeHtml('tala-subject-specs')
            ->assertSeeHtml('tala-subject-lead')
            ->assertDontSeeHtml('tala-catalog-description-card');

        Livewire::test(ViewCourse::class, ['record' => $nonCreditSpec->course_id])
            ->assertOk()
            ->assertSee('Subject overview')
            ->assertSee('Non-credit / Bridging')
            ->assertSee('Foundational algebra and pre-calculus refresher for incoming students.')
            ->assertSee('Excluded from GWA')
            ->assertSeeHtml('tala-subject-specs')
            ->assertDontSeeHtml('tala-catalog-description-card');
    }

    public function test_courses_table_status_filter_matches_status_column_badges_including_retired_courses(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $activeCourse = Course::factory()->create(['code' => 'FILT-ACT', 'state' => Course::StateActive]);
        CourseSpecification::factory()->for($activeCourse)->create(['state' => CourseSpecification::StateActive]);

        $draftCourse = Course::factory()->create(['code' => 'FILT-DRF', 'state' => Course::StateActive]);
        CourseSpecification::factory()->for($draftCourse)->create(['state' => CourseSpecification::StateActive]);
        CourseSpecification::factory()->for($draftCourse)->create(['state' => CourseSpecification::StateDraft]);

        $retiredWithSpecs = Course::factory()->create(['code' => 'FILT-RET1', 'state' => Course::StateRetired]);
        CourseSpecification::factory()->for($retiredWithSpecs)->create(['state' => CourseSpecification::StateActive]);
        CourseSpecification::factory()->for($retiredWithSpecs)->create(['state' => CourseSpecification::StateDraft]);

        $retiredSpecOnly = Course::factory()->create(['code' => 'FILT-RET2', 'state' => Course::StateActive]);
        CourseSpecification::factory()->for($retiredSpecOnly)->create(['state' => CourseSpecification::StateRetired]);

        $table = Livewire::test(ListCourses::class)->searchTable('FILT-');

        $table->filterTable('state', Course::StateActive)
            ->assertCanSeeTableRecords([$activeCourse])
            ->assertCanNotSeeTableRecords([$draftCourse, $retiredWithSpecs, $retiredSpecOnly]);

        $table->filterTable('state', CourseSpecification::StateDraft)
            ->assertCanSeeTableRecords([$draftCourse])
            ->assertCanNotSeeTableRecords([$activeCourse, $retiredWithSpecs, $retiredSpecOnly]);

        $table->filterTable('state', Course::StateRetired)
            ->assertCanSeeTableRecords([$retiredWithSpecs, $retiredSpecOnly])
            ->assertCanNotSeeTableRecords([$activeCourse, $draftCourse]);
    }

    public function test_view_course_revise_reuses_draft_renders_diff_summary_and_guards_discard_draft(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $course = Course::factory()->create(['code' => 'DIFF-101', 'state' => Course::StateActive]);
        $earlierActive = CourseSpecification::factory()->for($course)->create([
            'title' => 'Data Structures (Legacy)',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $currentActive = CourseSpecification::factory()->for($course)->create([
            'title' => 'Data Structures',
            'description' => 'Core linear and tree data structures.',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($currentActive)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);

        // Change history distinguishes Current vs Active (earlier).
        $view = Livewire::test(ViewCourse::class, ['record' => $course->id])
            ->assertSee('Current')
            ->assertSee('Active (earlier)');

        // First reviseSubject creates 1 Draft and immediately mounts editRevision.
        $view->callAction('reviseSubject')
            ->assertHasNoActionErrors()
            ->assertActionMounted('editRevision')
            ->assertSeeHtml('tala-catalog-panel__footer')
            ->assertSee('No changes made yet compared to the current active version.')
            ->assertSee('Discard draft')
            ->assertSee('Edit draft')
            ->assertSee('Activate changes');

        $draft = $course->specifications()->where('state', CourseSpecification::StateDraft)->sole();
        $this->assertSame(['has_active' => true, 'changes' => []], $view->instance()->draftChangesSummary($draft));

        // Clicking Edit teaching details again while viewing Current active reuses the existing draft without duplicating.
        $view->unmountAction()
            ->call('selectRevision', $currentActive->id)
            ->callAction('reviseSubject')
            ->assertHasNoActionErrors()
            ->assertNotified('Opened existing draft to edit')
            ->assertSet('selectedRevisionId', $draft->id);
        $this->assertSame(1, $course->specifications()->where('state', CourseSpecification::StateDraft)->count());

        // Editing the draft updates draftChangesSummary() with exact changed fields.
        $view->callAction('editRevision', data: [
            'title' => 'Data Structures',
            'credit_units' => 4,
            'description' => 'Core linear, tree, and graph data structures.',
            'academic_classification' => $currentActive->academic_classification,
            'scheduling_treatment' => $currentActive->scheduling_treatment,
            'allowed_modalities' => $currentActive->allowed_modalities,
            'same_faculty_default' => $currentActive->same_faculty_default,
        ])->assertHasNoActionErrors();

        $draft->refresh()->load('components', 'requirements');
        $summary = $view->instance()->draftChangesSummary($draft);
        $this->assertTrue($summary['has_active']);
        $this->assertContains('Units (3 → 4)', $summary['changes']);
        $this->assertContains('Description updated', $summary['changes']);
        $view->assertSee('Changes from current active version:');

        // Guard: linking the draft to a draft curriculum blocks discarding in both UI and service.
        $program = Program::factory()->create(['code' => 'BSIT-DF']);
        $draftCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT 2027 Draft',
            'state' => CurriculumVersion::StateDraft,
        ]);
        $entry = CurriculumEntry::factory()->for($draftCurriculum)->for($draft, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
        ]);

        Livewire::test(ViewCourse::class, ['record' => $course->id, 'selectedRevisionId' => $draft->id])
            ->assertActionHidden('discardDraft')
            ->assertSee('Remove this draft from curricula before discarding.');

        try {
            app(CourseSpecificationLifecycleService::class)->discardDraft($registrar, $draft);
            $this->fail('Expected discardDraft to reject a draft referenced by a curriculum.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('BSIT-DF', $message);
        }

        // Unlinking allows discarding and returns the page to the Current active revision.
        $entry->delete();

        Livewire::test(ViewCourse::class, ['record' => $course->id, 'selectedRevisionId' => $draft->id])
            ->assertActionVisible('discardDraft')
            ->callAction('discardDraft')
            ->assertHasNoActionErrors()
            ->assertNotified('Draft discarded')
            ->assertSet('selectedRevisionId', $currentActive->id);

        $this->assertDatabaseMissing('course_specifications', ['id' => $draft->id]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'course_specification_draft_discarded',
            'subject_type' => Course::class,
            'subject_id' => $course->id,
        ]);
    }

    public function test_curriculum_direct_edit_skips_fork_on_pure_move_forks_on_spec_change_and_enforces_cross_year_uniqueness(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $workbench = app(CurriculumWorkbenchService::class);

        $program = Program::factory()->create(['code' => 'BSIT-UNIQ', 'duration_years' => 4, 'is_active' => true]);
        $draftCurriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);

        $course1 = Course::factory()->create(['code' => 'UNIQ-101']);
        $spec1 = CourseSpecification::factory()->for($course1)->create([
            'title' => 'Computing Foundations',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($spec1)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);

        $course2 = Course::factory()->create(['code' => 'UNIQ-102']);
        $spec2 = CourseSpecification::factory()->for($course2)->create([
            'title' => 'Discrete Structures',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($spec2)->create([
            'component_type' => CourseComponent::TypeLecture,
            'meeting_pattern' => '2x90',
            'weekly_contact_hours' => 3,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);

        $entry1 = CurriculumEntry::factory()->for($draftCurriculum)->for($spec1, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);
        $entry2 = CurriculumEntry::factory()->for($draftCurriculum)->for($spec2, 'courseSpecification')->create([
            'year_level' => '2',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        // Pure placement move in direct 'edit' mode does NOT fork a new CourseSpecification revision.
        $page = Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftCurriculum->getRouteKey()])
            ->call('selectCurriculumSubject', $entry1->id)
            ->assertSet('subjectMode', 'edit')
            ->set('subjectData.year_level', 2)
            ->set('subjectData.term_type', Term::TypeSecondSemester)
            ->set('subjectData.confirm_placement_move', true)
            ->call('saveCurriculumSubject')
            ->assertHasNoErrors();

        $entry1->refresh();
        $this->assertSame('2', (string) $entry1->year_level);
        $this->assertSame(Term::TypeSecondSemester, $entry1->term_type);
        $this->assertSame($spec1->id, $entry1->course_specification_id);
        $this->assertSame(1, $course1->specifications()->count());

        // Teaching detail change forks a new Draft CourseSpecification without mutating the Active source.
        $page->call('selectCurriculumSubject', $entry1->id)
            ->set('subjectData.title', 'Computing Foundations (Updated)')
            ->call('saveCurriculumSubject')
            ->assertHasNoErrors();

        $entry1->refresh();
        $this->assertNotSame($spec1->id, $entry1->course_specification_id);
        $this->assertSame(CourseSpecification::StateDraft, $entry1->courseSpecification->state);
        $this->assertSame('Computing Foundations (Updated)', $entry1->courseSpecification->title);
        $this->assertSame('Computing Foundations', $spec1->fresh()->title);

        // Cross-year uniqueness blocks placing the same course_id in another year of the same curriculum.
        try {
            $workbench->createEntry($registrar, $draftCurriculum, [
                'course_specification_id' => $spec1->id,
                'year_level' => 3,
                'term_type' => Term::TypeFirstSemester,
            ]);
            $this->fail('Expected cross-year duplicate course placement to throw ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term_label', $exception->errors());
            $message = collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('UNIQ-101', $message);
            $this->assertStringContainsString('Year 2', $message);
        }

        // Replace subject also blocks swapping entry2 to a course already placed in the curriculum.
        $page->call('selectCurriculumSubject', $entry2->id)
            ->callAction('replaceSelectedSubject', data: [
                'course_specification_id' => $spec1->id,
            ]);
        $this->assertSame($spec2->id, $entry2->fresh()->course_specification_id);
    }

    public function test_review_curriculum_version_renders_single_add_subjects_cta_on_empty_semesters_and_summer(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $program = Program::factory()->create(['code' => 'BSIT-CTA', 'duration_years' => 4, 'is_active' => true]);
        $draftCurriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);

        $page = Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftCurriculum->getRouteKey()])
            ->call('enableSummerForYear', 1)
            ->assertSee('Remove Summer');

        // Empty 1st Sem, 2nd Sem, and Summer each render exactly 1 "Add subjects" button (inside the empty state, none in the header).
        $emptyHtml = $page->html();
        $this->assertSame(1, substr_count($emptyHtml, "wire:click=\"openSubjectEditor('existing', 1, '".Term::TypeFirstSemester."')\""));
        $this->assertSame(1, substr_count($emptyHtml, "wire:click=\"openSubjectEditor('existing', 1, '".Term::TypeSecondSemester."')\""));
        $this->assertSame(1, substr_count($emptyHtml, "wire:click=\"openSubjectEditor('existing', 1, '".Term::TypeSpecialTerm."')\""));

        // Adding a subject to 1st Semester switches 1st Semester from empty-state CTA to header CTA (still exactly 1 per semester).
        $course = Course::factory()->create(['code' => 'CTA-101']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        CurriculumEntry::factory()->for($draftCurriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
        ]);

        $populatedHtml = Livewire::test(ReviewCurriculumVersion::class, ['record' => $draftCurriculum->getRouteKey()])
            ->call('enableSummerForYear', 1)
            ->html();
        $this->assertSame(1, substr_count($populatedHtml, "wire:click=\"openSubjectEditor('existing', 1, '".Term::TypeFirstSemester."')\""));
        $this->assertSame(1, substr_count($populatedHtml, "wire:click=\"openSubjectEditor('existing', 1, '".Term::TypeSecondSemester."')\""));
        $this->assertSame(1, substr_count($populatedHtml, "wire:click=\"openSubjectEditor('existing', 1, '".Term::TypeSpecialTerm."')\""));
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
