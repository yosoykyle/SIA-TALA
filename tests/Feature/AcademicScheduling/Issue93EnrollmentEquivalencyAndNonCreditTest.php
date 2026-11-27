<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\AcademicSetup\CourseSpecificationLifecycleService;
use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Enrollment\RegistrationEntryContext;
use App\Actions\Enrollment\StudentUnitLoadService;
use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\RecordTermCohort;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Student\Pages\Dashboard as StudentDashboard;
use App\Filament\Student\Pages\ScheduleView;
use App\Models\AcademicYear;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CorVersion;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93EnrollmentEquivalencyAndNonCreditTest extends TestCase
{
    use DatabaseTransactions;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach ([
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $registrarRole = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrarRole->syncPermissions([
            Permission::findOrCreate('manage-schedules', 'web'),
            Permission::findOrCreate('approve-documents', 'web'),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    #[Test]
    public function registrar_view_enrollment_includes_shared_ge_and_equivalent_sections_and_resolves_curriculum_position_options(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $bsit = Program::factory()->create(['code' => 'BSIT-C3', 'name' => 'BS Information Technology']);
        $bshm = Program::factory()->create(['code' => 'BSHM-C3', 'name' => 'BS Hospitality Management']);

        $bsitCurriculum = CurriculumVersion::factory()->for($bsit)->create(['state' => CurriculumVersion::StateActive]);
        $bshmCurriculum = CurriculumVersion::factory()->for($bshm)->create(['state' => CurriculumVersion::StateActive]);

        $term = $this->activeTermWithOpenWindows(Term::TypeFirstSemester);

        $geSpec = $this->activeRecurringSpecification('GE101-C3', 'Understanding the Self', 3.00);
        $equivGeSpec = $this->activeRecurringSpecification('GE101EQ-C3', 'Self and Society Equivalent', 3.00);
        $hmY1Spec = $this->activeRecurringSpecification('HM101-C3', 'Macro Perspective of Tourism', 3.00);
        $hmY2Spec = $this->activeRecurringSpecification('HM201-C3', 'Hospitality Operations', 3.00);

        CourseRequirement::query()->create([
            'course_specification_id' => $geSpec->id,
            'rule_type' => CourseRequirement::TypeEquivalency,
            'group_key' => 'EQUIV-GE101-C3',
            'related_course_id' => $equivGeSpec->course_id,
            'state' => CourseRequirement::StateActive,
            'sequence' => 1,
        ]);

        // Host GE and Equivalent GE live under BSIT Second Year.
        $bsitGeEntry = CurriculumEntry::factory()->for($bsitCurriculum)->for($geSpec, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);
        $bsitEquivEntry = CurriculumEntry::factory()->for($bsitCurriculum)->for($equivGeSpec, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 2,
        ]);

        // BSHM places GE101 and HM101 in First Year, and HM201 in Second Year.
        CurriculumEntry::factory()->for($bshmCurriculum)->for($geSpec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);
        $bshmY1Entry = CurriculumEntry::factory()->for($bshmCurriculum)->for($hmY1Spec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 2,
        ]);
        $bshmY2Entry = CurriculumEntry::factory()->for($bshmCurriculum)->for($hmY2Spec, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);

        $bsitCohort = TermCohort::factory()->for($term)->for($bsitCurriculum)->create([
            'program_id' => $bsit->id,
            'reference' => 'BSIT-2A-C3',
            'state' => 'Confirmed',
            'confirmed_count' => 20,
        ]);
        $bshmCohort = TermCohort::factory()->for($term)->for($bshmCurriculum)->create([
            'program_id' => $bshm->id,
            'reference' => 'BSHM-1A-C3',
            'state' => 'Confirmed',
            'confirmed_count' => 20,
        ]);

        $sharedGeOffering = TermOffering::factory()->for($term)->for($bsitGeEntry, 'curriculumEntry')->create([
            'category' => TermOffering::CategoryRegular,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $sharedGeSection = Section::factory()->for($sharedGeOffering, 'termOffering')->create([
            'course_specification_id' => $geSpec->id,
            'code' => 'GE101-SHARED-C3',
            'source' => Section::SourceShared,
            'capacity' => 40,
            'state' => Section::StateOpen,
        ]);
        $sharedGeSection->cohorts()->attach([
            $bsitCohort->id => ['expected_count' => 20],
            $bshmCohort->id => ['expected_count' => 20],
        ]);

        $equivOffering = TermOffering::factory()->for($term)->for($bsitEquivEntry, 'curriculumEntry')->create([
            'category' => TermOffering::CategoryRegular,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $equivSection = Section::factory()->for($equivOffering, 'termOffering')->create([
            'course_specification_id' => $equivGeSpec->id,
            'code' => 'GE101EQ-SHARED-C3',
            'source' => Section::SourceShared,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);

        $bshmY1Offering = TermOffering::factory()->for($term)->for($bshmY1Entry, 'curriculumEntry')->create([
            'category' => TermOffering::CategoryRegular,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $bshmY1Section = Section::factory()->for($bshmY1Offering, 'termOffering')->create([
            'course_specification_id' => $hmY1Spec->id,
            'code' => 'HM101-1A-C3',
            'source' => Section::SourceRegular,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $bshmY1Section->cohorts()->attach([$bshmCohort->id => ['expected_count' => 20]]);

        $bshmY2Offering = TermOffering::factory()->for($term)->for($bshmY2Entry, 'curriculumEntry')->create([
            'category' => TermOffering::CategoryRegular,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $bshmY2Section = Section::factory()->for($bshmY2Offering, 'termOffering')->create([
            'course_specification_id' => $hmY2Spec->id,
            'code' => 'HM201-2A-C3',
            'source' => Section::SourceRegular,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $bshmY2Section->cohorts()->attach([$bshmCohort->id => ['expected_count' => 20]]);

        $timetable = PublishedTimetableVersion::factory()->for($term)->create([
            'state' => PublishedTimetableVersion::StatePublished,
            'published_by' => $registrar->id,
        ]);
        $room = Room::factory()->create(['is_active' => true]);
        foreach ([
            [$sharedGeSection, 1],
            [$equivSection, 2],
            [$bshmY1Section, 3],
            [$bshmY2Section, 4],
        ] as [$section, $day]) {
            PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
                'section_id' => $section->id,
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '11:00:00',
                'modality' => TermOffering::ModalityFaceToFace,
                'location_label' => $room->name,
            ]);
        }

        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'program_id' => $bshm->id,
            'curriculum_version_id' => $bshmCurriculum->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);
        $case = Enrollment::factory()->for($profile)->for($term)->create([
            'credential_user_id' => $studentUser->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'lock_version' => 1,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Mixing BSHM Year 1 HM101 + Shared GE hosted on BSIT Year 2 (which is Year 1 in BSHM) does not conflict in BSHM curriculum.
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('prepareProposal')
            ->assertMountedActionModalSee('GE101-SHARED-C3')
            ->assertMountedActionModalSee('GE101EQ-SHARED-C3')
            ->assertMountedActionModalSee('HM101-1A-C3')
            ->assertMountedActionModalSee('HM201-2A-C3')
            ->setActionData(['section_ids' => [$sharedGeSection->id, $bshmY1Section->id]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $case->refresh();
        $this->assertNotNull($case->currentProposalVersion);
        $this->assertSame($bshmCurriculum->id, $case->currentProposalVersion->curriculum_version_id);
        $this->assertSame('First Year', data_get($case->currentProposalVersion->source_snapshot, 'unit_load.year_level'));

        // Mixing Shared GE (Year 1 in BSHM, hosted on BSIT) + BSHM Year 2 HM201 exposes BSHM curriculum position options and saves cleanly.
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $case->id])
            ->mountAction('prepareProposal')
            ->setActionData(['section_ids' => [$sharedGeSection->id, $bshmY2Section->id]])
            ->assertMountedActionModalSee('Curriculum term for the normal unit load')
            ->setActionData([
                'section_ids' => [$sharedGeSection->id, $bshmY2Section->id],
                'curriculum_position' => 'First Year|First Semester|'.Term::TypeFirstSemester,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $case->refresh();
        $this->assertSame('First Year', data_get($case->currentProposalVersion->source_snapshot, 'unit_load.year_level'));
        $this->assertSame('6.00', data_get($case->currentProposalVersion->source_snapshot, 'unit_load.normal_total'));

        // Prepare adjustment also surfaces Shared GE and Equivalent cross-program sections.
        $cor = CorVersion::factory()->for($case)->create([
            'registration_proposal_version_id' => $case->currentProposalVersion->id,
            'published_timetable_version_id' => $timetable->id,
            'version' => 1,
        ]);
        $case->update([
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'current_cor_version_id' => $cor->id,
            'officially_enrolled_at' => now(),
        ]);
        CourseEnrollment::factory()->create([
            'enrollment_id' => $case->id,
            'term_offering_id' => $bshmY1Offering->id,
            'section_id' => $bshmY1Section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);

        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $case->fresh()->id])
            ->mountAction('prepareAdjustmentProposal', ['change_type' => 'Add'])
            ->assertMountedActionModalSee('GE101-SHARED-C3')
            ->assertMountedActionModalSee('GE101EQ-SHARED-C3');
    }

    #[Test]
    public function special_term_retake_ceiling_allows_single_position_and_multi_position_regular_retake_classes(): void
    {
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create([
            'type' => Term::TypeSpecialTerm,
            'default_max_units' => 99,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
        ]);

        $regularEntryA = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => CourseSpecification::factory()->create(['credit_units' => 3.00])->id,
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
        ]);
        $regularEntryB = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'course_specification_id' => CourseSpecification::factory()->create(['credit_units' => 3.00])->id,
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
        ]);

        $retakeSectionA = Section::factory()->create([
            'term_offering_id' => TermOffering::factory()->create([
                'term_id' => $term->id,
                'curriculum_entry_id' => $regularEntryA->id,
                'category' => TermOffering::CategoryRegular,
                'state' => TermOffering::StateScheduled,
            ])->id,
            'state' => Section::StateOpen,
        ]);
        $retakeSectionB = Section::factory()->create([
            'term_offering_id' => TermOffering::factory()->create([
                'term_id' => $term->id,
                'curriculum_entry_id' => $regularEntryB->id,
                'category' => TermOffering::CategoryRegular,
                'state' => TermOffering::StateScheduled,
            ])->id,
            'state' => Section::StateOpen,
        ]);

        $service = app(StudentUnitLoadService::class);
        $summerKey = 'Special Term|Special Term|'.Term::TypeSpecialTerm;

        $singleSnapshot = $service->snapshotForSections($enrollment, collect([$retakeSectionA]), $summerKey);
        $this->assertSame('Special Term', $singleSnapshot['year_level']);
        $this->assertSame(Term::TypeSpecialTerm, $singleSnapshot['term_type']);
        $this->assertSame('9.00', $singleSnapshot['normal_total']);
        $this->assertSame('3.00', $singleSnapshot['requested_total']);
        $this->assertTrue($singleSnapshot['unit_load_passes']);

        $samePositionTwoClassesSnapshot = $service->snapshotForSections(
            $enrollment,
            collect([$retakeSectionA, $retakeSectionB]),
            $summerKey,
        );
        $this->assertSame('9.00', $samePositionTwoClassesSnapshot['normal_total']);
        $this->assertSame('6.00', $samePositionTwoClassesSnapshot['requested_total']);
        $this->assertTrue($samePositionTwoClassesSnapshot['unit_load_passes']);
    }

    #[Test]
    public function active_course_equivalency_is_honored_in_class_confirmation_curriculum_evaluation_and_prerequisite_eligibility(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $bsit = Program::factory()->create(['code' => 'BSIT-H8']);
        $bshm = Program::factory()->create(['code' => 'BSHM-H8']);
        $bsitCurriculum = CurriculumVersion::factory()->for($bsit)->create(['state' => CurriculumVersion::StateActive]);
        $bshmCurriculum = CurriculumVersion::factory()->for($bshm)->create(['state' => CurriculumVersion::StateActive]);

        $term = $this->activeTermWithOpenWindows(Term::TypeFirstSemester);

        $itMathSpec = $this->activeRecurringSpecification('IT-MATH101', 'Mathematics in the Modern World (IT)', 3.00);
        $hmMathSpec = $this->activeRecurringSpecification('HM-MATH101', 'Mathematics in the Modern World (HM)', 3.00);
        $hmStatsSpec = $this->activeRecurringSpecification('HM-STAT201', 'Applied Hospitality Statistics', 3.00);

        // Equivalency defined on IT-MATH101 pointing to HM-MATH101
        CourseRequirement::query()->create([
            'course_specification_id' => $itMathSpec->id,
            'rule_type' => CourseRequirement::TypeEquivalency,
            'group_key' => 'EQUIV-MATH101',
            'related_course_id' => $hmMathSpec->course_id,
            'state' => CourseRequirement::StateActive,
            'sequence' => 1,
        ]);

        // HM-STAT201 requires HM-MATH101 as a prerequisite
        CourseRequirement::query()->create([
            'course_specification_id' => $hmStatsSpec->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'PREREQ-HM-MATH101',
            'related_course_id' => $hmMathSpec->course_id,
            'state' => CourseRequirement::StateActive,
            'sequence' => 1,
        ]);

        $bsitMathEntry = CurriculumEntry::factory()->for($bsitCurriculum)->for($itMathSpec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);
        $bshmMathEntry = CurriculumEntry::factory()->for($bshmCurriculum)->for($hmMathSpec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);
        $bshmStatsEntry = CurriculumEntry::factory()->for($bshmCurriculum)->for($hmStatsSpec, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);

        $bsitCohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $bsitCurriculum->id,
            'reference' => 'BSIT-1A-H8',
            'confirmed_count' => 20,
        ]);
        $bshmCohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $bshmCurriculum->id,
            'reference' => 'BSHM-1A-H8',
            'confirmed_count' => 20,
        ]);

        // 1) ConfirmClassOffering allows linking BSHM cohort whose curriculum contains the equivalent course HM-MATH101.
        $offering = TermOffering::factory()->for($term)->for($bsitMathEntry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 40,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'course_specification_id' => $itMathSpec->id,
            'code' => 'MATH101-SHARED-H8',
            'capacity' => 40,
            'state' => Section::StatePlanned,
        ]);
        SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'MATH101-SHARED-H8',
            'expected_count' => 40,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section = app(PrepareClassOffering::class)->execute($term, $section, $registrar, [
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);

        $confirmedSection = app(ConfirmClassOffering::class)->execute($section, $registrar, [
            $bsitCohort->id => 20,
            $bshmCohort->id => 20,
        ]);
        $this->assertNotNull($confirmedSection->confirmed_at);
        $this->assertEqualsCanonicalizing(
            [$bsitCohort->id, $bshmCohort->id],
            $confirmedSection->cohorts->pluck('id')->all(),
        );

        // 2) Student in BSHM passes IT-MATH101 -> CurriculumEvaluation marks BSHM's HM-MATH101 entry Completed and counts its units!
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $bshmStudent = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'program_id' => $bshm->id,
            'curriculum_version_id' => $bshmCurriculum->id,
        ]);

        $this->recordReleasedPassingAttempt($bshmStudent, $term, $offering, $confirmedSection, $faculty, $registrar, '1.50');

        $evaluation = app(CurriculumEvaluation::class)->forStudent($bshmStudent);
        $mathRow = collect($evaluation['required'])->firstWhere('curriculum_entry.id', $bshmMathEntry->id);
        $this->assertNotNull($mathRow);
        $this->assertSame('Completed', $mathRow['status']);
        $this->assertSame('3.00', $mathRow['credited_units']);
        $this->assertSame('3.00', $evaluation['completed_units']);

        // 3) satisfiedCourseIds() includes both IT-MATH101 and HM-MATH101, satisfying HM-STAT201's prerequisite!
        $eligibility = app(RegistrationAcademicEligibilityQuery::class);
        $satisfiedIds = $eligibility->satisfiedCourseIds($bshmStudent);
        $this->assertContains((int) $itMathSpec->course_id, $satisfiedIds->all());
        $this->assertContains((int) $hmMathSpec->course_id, $satisfiedIds->all());

        $nextTerm = $this->activeTermWithOpenWindows(Term::TypeFirstSemester);
        $statsOffering = TermOffering::factory()->for($nextTerm)->for($bshmStatsEntry, 'curriculumEntry')->create([
            'state' => TermOffering::StateScheduled,
        ]);
        $nextEnrollment = Enrollment::factory()->for($bshmStudent)->for($nextTerm)->create([
            'credential_user_id' => $studentUser->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $this->assertTrue($eligibility->passes($nextEnrollment, $bshmCurriculum, collect([$statsOffering])));
    }

    #[Test]
    public function student_dashboard_renders_latest_official_enrollment_when_two_concurrent_active_official_enrollments_exist(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $program = Program::factory()->create(['code' => 'BSIT-H5']);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $profile = StudentProfile::factory()->for($studentUser, 'user')->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
            'first_name' => 'Maria',
            'last_name' => 'Concurrent',
            'student_number' => '2026-H5-0001',
        ]);

        // Two concurrent StateActive terms without an open enrollment window -> RegistrationEntryContext returns enrollment = null.
        $academicYear = AcademicYear::factory()->create(['state' => AcademicYear::StateActive]);
        $term1 = Term::factory()->for($academicYear)->create([
            'label' => '1st Semester Ongoing H5',
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-12-15',
        ]);
        $term2 = Term::factory()->for($academicYear)->create([
            'label' => '2nd Semester Early H5',
            'type' => Term::TypeSecondSemester,
            'state' => Term::StateActive,
            'starts_on' => '2027-01-10',
            'ends_on' => '2027-05-20',
        ]);

        $this->createOfficialEnrollmentWithPublishedClass(
            $studentUser,
            $profile,
            $term1,
            $curriculum,
            $faculty,
            $registrar,
            'CS101-H5',
            'Ongoing First Sem Subject',
            'BSIT-1A-H5',
        );
        $this->createOfficialEnrollmentWithPublishedClass(
            $studentUser,
            $profile,
            $term2,
            $curriculum,
            $faculty,
            $registrar,
            'CS102-H5',
            'Early Second Sem Subject',
            'BSIT-1B-H5',
        );

        $entryContext = app(RegistrationEntryContext::class)->forUser($studentUser);
        $this->assertNull($entryContext['enrollment']);

        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($studentUser)
            ->test(StudentDashboard::class)
            ->assertSee('Your enrollment is official')
            ->assertSee('2nd Semester Early H5');

        Livewire::actingAs($studentUser)
            ->test(ScheduleView::class)
            ->assertSee('2nd Semester Early H5')
            ->assertSee('Early Second Sem Subject')
            ->assertSee('1st Semester Ongoing H5');
    }

    #[Test]
    public function zero_unit_non_credit_subject_passes_lifecycle_activation_workbench_validation_and_curriculum_readiness(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'BSIT-H7', 'duration_years' => 4]);
        $draftCurriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'BSIT-H7-2026',
            'name' => 'BSIT H7 Curriculum',
            'state' => CurriculumVersion::StateDraft,
        ]);

        // 1) Quick-place a 0-unit NON_CREDIT subject via CurriculumWorkbenchService
        $entry = app(CurriculumWorkbenchService::class)->quickPlaceSubject($registrar, $draftCurriculum, [
            'code' => 'BRIDGE101',
            'title' => 'Bridging Mathematics',
            'credit_units' => 0,
            'academic_classification' => CourseSpecification::AcademicClassificationNonCredit,
            'year_level' => 1,
            'term_type' => Term::TypeFirstSemester,
            'room_schedule_preset' => 'lecture',
        ]);

        $spec = $entry->courseSpecification;
        $this->assertNotNull($spec);
        $this->assertSame('0.00', (string) $spec->credit_units);
        $this->assertSame(CourseSpecification::AcademicClassificationNonCredit, $spec->academic_classification);

        // 2) CourseSpecificationLifecycleService::readinessErrors returns no errors for 0-unit NON_CREDIT subject
        $this->assertSame([], app(CourseSpecificationLifecycleService::class)->readinessErrors($spec));

        // 3) Updating specification with 0 units and NON_CREDIT succeeds, whereas 0 units for ORDINARY is rejected
        $updatedSpec = app(CurriculumWorkbenchService::class)->updateSpecification($registrar, $entry, [
            'title' => 'Bridging Mathematics Revised',
            'credit_units' => 0,
            'academic_classification' => CourseSpecification::AcademicClassificationNonCredit,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'same_faculty_default' => true,
            'components' => [[
                'component_type' => CourseComponent::TypeLecture,
                'weekly_contact_hours' => 1.0,
                'meeting_pattern' => '1x60',
                'room_type_default' => CourseComponent::RoomTypeLectureRoom,
                'requires_consecutive_block' => false,
                'same_faculty' => true,
                'sequence' => 1,
            ]],
        ]);
        $this->assertSame('0.00', (string) $updatedSpec->credit_units);

        try {
            app(CurriculumWorkbenchService::class)->quickPlaceSubject($registrar, $draftCurriculum, [
                'code' => 'ORDINARY0',
                'title' => 'Zero Unit Ordinary Subject',
                'credit_units' => 0,
                'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
                'year_level' => 1,
                'term_type' => Term::TypeFirstSemester,
                'room_schedule_preset' => 'lecture',
            ]);
            $this->fail('Credit-bearing ORDINARY subjects with 0 units must remain blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credit_units', $exception->errors());
        }

        // 4) ReviewCurriculumVersion reports no 0-unit blocker and curriculum activates cleanly
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)
            ->test(ReviewCurriculumVersion::class, ['record' => $draftCurriculum->getRouteKey()])
            ->assertDontSee('must have more than 0 units.');

        $activated = app(CurriculumVersionLifecycleService::class)->activate($registrar, $draftCurriculum->fresh());
        $this->assertSame(CurriculumVersion::StateActive, $activated->state);
        $this->assertSame(CourseSpecification::StateActive, $updatedSpec->fresh()->state);
    }

    private function staff(string $role): User
    {
        $this->seq++;
        $user = User::factory()->create([
            'name' => 'Staff '.$role.' '.$this->seq,
            'email' => Str::slug($role).'-h93-'.$this->seq.'@servitech.edu.ph',
            'status' => User::StatusActive,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function activeTermWithOpenWindows(string $type): Term
    {
        $this->seq++;
        $term = Term::factory()->create([
            'type' => $type,
            'state' => Term::StateActive,
            'starts_on' => now()->subDays(10)->toDateString(),
            'ends_on' => now()->addMonths(3)->toDateString(),
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'version' => 1,
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        foreach ([
            TermCalendarWindow::TypeEnrollment,
            TermCalendarWindow::TypeEnrollmentAdjustment,
            TermCalendarWindow::TypeCourseDrop,
        ] as $windowType) {
            TermCalendarWindow::factory()->for($package, 'package')->create([
                'window_type' => $windowType,
                'opens_on' => now()->subDays(5)->toDateString(),
                'closes_on' => now()->addDays(15)->toDateString(),
            ]);
        }
        foreach ([1, 2, 3, 4, 5, 6] as $day) {
            TermTeachingGridRow::factory()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
            ]);
        }

        return $term;
    }

    private function activeRecurringSpecification(string $code, string $title, float $units): CourseSpecification
    {
        $course = Course::factory()->create([
            'code' => $code,
            'state' => Course::StateActive,
        ]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => $title,
            'credit_units' => $units,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [CourseSpecification::ModalityFaceToFace],
            'state' => CourseSpecification::StateActive,
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'meeting_pattern' => '1x180',
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
            'sequence' => 1,
        ]);

        return $spec;
    }

    private function recordReleasedPassingAttempt(
        StudentProfile $student,
        Term $term,
        TermOffering $offering,
        Section $section,
        User $faculty,
        User $registrar,
        string $resultCode,
    ): CourseEnrollment {
        $enrollment = Enrollment::factory()->create([
            'credential_user_id' => $student->user_id,
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3.00,
            'added_at' => now(),
        ]);
        $assignment = ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'EQ-ASSIGN-'.$courseEnrollment->id,
            'assigned_by' => $registrar->id,
            'effective_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'teaching_assignment_id' => $assignment->id,
            'state' => GradeRoster::StateReleased,
            'submitted_by' => $faculty->id,
            'submitted_at' => now(),
            'released_by' => $registrar->id,
            'released_at' => now(),
        ]);
        $row = GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => $resultCode,
            'current_outcome_category' => GradeRosterRow::CategoryPassing,
            'released_at' => now(),
        ]);
        GradeOutcomeEvent::query()->create([
            'grade_roster_row_id' => $row->id,
            'source_key' => 'eq-initial:'.$row->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => $resultCode,
            'source_term_ends_on' => $term->ends_on,
            'new_value' => (float) $resultCode,
            'new_category' => GradeRosterRow::CategoryPassing,
            'reason' => 'Initial release',
            'recorded_by' => $registrar->id,
            'released_at' => now(),
        ]);

        return $courseEnrollment;
    }

    private function createOfficialEnrollmentWithPublishedClass(
        User $studentUser,
        StudentProfile $profile,
        Term $term,
        CurriculumVersion $curriculum,
        User $faculty,
        User $registrar,
        string $courseCode,
        string $courseTitle,
        string $sectionCode,
    ): Enrollment {
        $spec = $this->activeRecurringSpecification($courseCode, $courseTitle, 3.00);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_label' => $term->label,
            'term_type' => $term->type,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'state' => TermOffering::StateScheduled,
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 25,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'course_specification_id' => $spec->id,
            'code' => $sectionCode,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create([
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);
        $room = Room::factory()->create(['is_active' => true]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => $room->name,
        ]);

        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'credential_user_id' => $studentUser->id,
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $proposal = RegistrationProposalVersion::factory()->for($enrollment)->create([
            'curriculum_version_id' => $curriculum->id,
            'published_timetable_version_id' => $timetable->id,
            'state' => RegistrationProposalVersion::StateConfirmed,
        ]);
        $cor = CorVersion::factory()->for($enrollment)->create([
            'registration_proposal_version_id' => $proposal->id,
            'published_timetable_version_id' => $timetable->id,
            'version' => 1,
        ]);
        $enrollment->update([
            'current_proposal_version_id' => $proposal->id,
            'current_cor_version_id' => $cor->id,
        ]);
        CourseEnrollment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);

        return $enrollment->fresh();
    }
}
