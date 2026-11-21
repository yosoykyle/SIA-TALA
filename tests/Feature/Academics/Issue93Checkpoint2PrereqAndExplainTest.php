<?php

namespace Tests\Feature\Academics;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Enrollment\EnrollmentProposalExplanationProjection;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Grades\OpenRegistrationImpactReviewsForGradeOutcome;
use App\Actions\Grades\RecordApprovedGradeCorrection;
use App\Actions\StudentLifecycle\LifecycleFinanceAction;
use App\Filament\Applicant\Pages\Dashboard as ApplicantDashboard;
use App\Filament\Applicant\Pages\Enrollment as ApplicantEnrollment;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Filament\Student\Pages\Academics as StudentAcademics;
use App\Filament\Student\Pages\Enrollment as StudentEnrollment;
use App\Models\AcademicDecision;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\ApplicationSubmissionVersion;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\Course;
use App\Models\CourseDropRecord;
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
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue93Checkpoint2PrereqAndExplainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'applicant',
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function earlier_passing_attempt_stays_satisfied_across_eligibility_curriculum_and_effect_when_later_attempt_fails_or_is_unresolved(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateSuperseded,
        ]);
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $student = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
        ]);

        $course1 = Course::factory()->create(['code' => 'CP2-101']);
        $spec1 = CourseSpecification::factory()->create([
            'course_id' => $course1->id,
            'title' => 'Foundations',
            'credit_units' => 3.00,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry1 = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $curriculum->id,
            'course_specification_id' => $spec1->id,
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        $course2 = Course::factory()->create(['code' => 'CP2-102']);
        $spec2 = CourseSpecification::factory()->create([
            'course_id' => $course2->id,
            'title' => 'Intermediate',
            'credit_units' => 3.00,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry2 = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $curriculum->id,
            'course_specification_id' => $spec2->id,
            'year_level' => 'First Year',
            'term_label' => 'Second Semester',
            'sequence' => 2,
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $spec2->id,
            'related_course_id' => $course1->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'g1',
            'state' => CourseRequirement::StateActive,
        ]);

        // Term 1: Passed CP2-101 with 2.00 (different revision of canonical course_id to also verify canonical course_id matching)
        $olderSpec1 = CourseSpecification::factory()->create([
            'course_id' => $course1->id,
            'title' => 'Foundations (Rev 1)',
            'credit_units' => 3.00,
            'state' => CourseSpecification::StateActive,
        ]);
        $olderEntry1 = CurriculumEntry::factory()->create([
            'curriculum_version_id' => CurriculumVersion::factory()->create(['program_id' => $program->id])->id,
            'course_specification_id' => $olderSpec1->id,
        ]);
        $term1 = Term::factory()->create(['starts_on' => now()->subMonths(12)->toDateString(), 'ends_on' => now()->subMonths(8)->toDateString()]);
        $this->recordOfficialCourseAttempt($student, $term1, $olderEntry1, $faculty, $registrar, '2.00', GradeRosterRow::CategoryPassing);

        // Term 2: Later failed attempt (5.00), plus later INC, DRP, and pending past-term attempts on same canonical course
        $term2 = Term::factory()->create(['starts_on' => now()->subMonths(7)->toDateString(), 'ends_on' => now()->subMonths(3)->toDateString()]);
        $this->recordOfficialCourseAttempt($student, $term2, $entry1, $faculty, $registrar, '5.00', GradeRosterRow::CategoryFailed);

        $term3 = Term::factory()->create(['starts_on' => now()->subMonths(6)->toDateString(), 'ends_on' => now()->subMonths(2)->toDateString()]);
        $this->recordOfficialCourseAttempt($student, $term3, $entry1, $faculty, $registrar, 'INC', GradeRosterRow::CategoryIncomplete);

        $term4 = Term::factory()->create(['starts_on' => now()->subMonths(5)->toDateString(), 'ends_on' => now()->subMonth()->toDateString()]);
        $droppedEnrollment = $this->recordOfficialCourseAttempt($student, $term4, $entry1, $faculty, $registrar, null, null, CourseEnrollment::StatusDropped);
        CourseDropRecord::query()->create([
            'enrollment_id' => $droppedEnrollment->enrollment_id,
            'course_enrollment_id' => $droppedEnrollment->id,
            'reason' => 'Approved drop',
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
        ]);

        // Term 5: Pending (unreleased) past-term attempt
        $term5 = Term::factory()->create(['starts_on' => now()->subMonths(4)->toDateString(), 'ends_on' => now()->subDays(5)->toDateString()]);
        $this->recordOfficialCourseAttempt($student, $term5, $entry1, $faculty, $registrar, null, null);

        // Next term enrollment case on the assigned superseded curriculum version
        $nextTerm = Term::factory()->create(['starts_on' => now()->addMonth()->toDateString(), 'ends_on' => now()->addMonths(5)->toDateString(), 'state' => Term::StateActive]);
        $nextOffering = TermOffering::factory()->create([
            'term_id' => $nextTerm->id,
            'curriculum_entry_id' => $entry2->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $nextCase = Enrollment::factory()->create([
            'credential_user_id' => $studentUser->id,
            'student_profile_id' => $student->id,
            'term_id' => $nextTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);

        $eligibility = app(RegistrationAcademicEligibilityQuery::class);
        $evaluation = app(CurriculumEvaluation::class)->forStudent($student);
        $effect = app(AcademicEnrollmentEffect::class)->forStudent($student, $nextTerm);
        $history = app(OfficialCourseResultProjection::class)->forStudent($student);

        // Unified satisfaction across all 3 projections
        $this->assertContains($course1->id, $eligibility->satisfiedCourseIds($student)->all());
        $this->assertTrue($eligibility->passes($nextCase, $curriculum, collect([$nextOffering])));
        $row1 = collect($evaluation['required'])->firstWhere('curriculum_entry.id', $entry1->id);
        $this->assertSame('Completed', $row1['status']);
        $this->assertSame('3.00', $row1['credited_units']);
        $this->assertSame(5, $row1['attempt_count']);
        $this->assertSame(AcademicDecision::EffectAllowed, $effect['effect']);
        // All attempts retained for academic history/averages
        $this->assertCount(5, $history);
    }

    #[Test]
    public function grade_correction_supersedes_only_its_own_attempt_and_reevaluates_remaining_attempts(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->create(['program_id' => $program->id, 'state' => CurriculumVersion::StateActive]);
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $student = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
        ]);

        $course1 = Course::factory()->create(['code' => 'CORR-101']);
        $spec1 = CourseSpecification::factory()->create(['course_id' => $course1->id, 'credit_units' => 3.00, 'state' => CourseSpecification::StateActive]);
        $entry1 = CurriculumEntry::factory()->create(['curriculum_version_id' => $curriculum->id, 'course_specification_id' => $spec1->id]);

        $course2 = Course::factory()->create(['code' => 'CORR-102']);
        $spec2 = CourseSpecification::factory()->create(['course_id' => $course2->id, 'credit_units' => 3.00, 'state' => CourseSpecification::StateActive]);
        $entry2 = CurriculumEntry::factory()->create(['curriculum_version_id' => $curriculum->id, 'course_specification_id' => $spec2->id]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $spec2->id,
            'related_course_id' => $course1->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'state' => CourseRequirement::StateActive,
        ]);

        $term1 = Term::factory()->create(['starts_on' => now()->subMonths(10)->toDateString(), 'ends_on' => now()->subMonths(6)->toDateString()]);
        $attempt1 = $this->recordOfficialCourseAttempt($student, $term1, $entry1, $faculty, $registrar, '2.00', GradeRosterRow::CategoryPassing);
        $term2 = Term::factory()->create(['starts_on' => now()->subMonths(5)->toDateString(), 'ends_on' => now()->subMonth()->toDateString()]);
        $attempt2 = $this->recordOfficialCourseAttempt($student, $term2, $entry1, $faculty, $registrar, '5.00', GradeRosterRow::CategoryFailed);

        $nextTerm = Term::factory()->create(['starts_on' => now()->addMonth()->toDateString(), 'ends_on' => now()->addMonths(5)->toDateString(), 'state' => Term::StateActive]);
        $nextOffering = TermOffering::factory()->create(['term_id' => $nextTerm->id, 'curriculum_entry_id' => $entry2->id, 'state' => TermOffering::StateScheduled]);
        $nextCase = Enrollment::factory()->create([
            'credential_user_id' => $studentUser->id,
            'student_profile_id' => $student->id,
            'term_id' => $nextTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);

        // Correcting attempt 1 from 2.00 -> 5.00 leaves no passing attempt
        app(RecordApprovedGradeCorrection::class)->execute($attempt1->gradeRosterRow, '5.00', 'Verified grade sheet error.', $registrar);

        $this->assertNotContains($course1->id, app(RegistrationAcademicEligibilityQuery::class)->satisfiedCourseIds($student)->all());
        $this->assertSame('Deficient', collect(app(CurriculumEvaluation::class)->forStudent($student)['required'])->firstWhere('curriculum_entry.id', $entry1->id)['status']);
        $this->assertSame(AcademicDecision::EffectAdvisingRequired, app(AcademicEnrollmentEffect::class)->forStudent($student, $nextTerm)['effect']);
        try {
            app(RegistrationAcademicEligibilityQuery::class)->assertEligible($nextCase, $curriculum, collect([$nextOffering]));
            $this->fail('Expected unmet prerequisite after correcting the only passing attempt.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        // Correcting attempt 2 from 5.00 -> 2.50 restores satisfaction without mutating attempt 1
        app(RecordApprovedGradeCorrection::class)->execute($attempt2->gradeRosterRow, '2.50', 'Verified second attempt.', $registrar);
        $this->assertContains($course1->id, app(RegistrationAcademicEligibilityQuery::class)->satisfiedCourseIds($student)->all());
        $this->assertSame('Completed', collect(app(CurriculumEvaluation::class)->forStudent($student)['required'])->firstWhere('curriculum_entry.id', $entry1->id)['status']);
        $this->assertSame(AcademicDecision::EffectAllowed, app(AcademicEnrollmentEffect::class)->forStudent($student, $nextTerm)['effect']);
    }

    #[Test]
    public function narrowed_impact_review_skips_passing_releases_and_opens_only_when_prerequisite_is_invalidated(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->create(['program_id' => $program->id, 'state' => CurriculumVersion::StateActive]);
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $student = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
        ]);

        $prereqA = Course::factory()->create(['code' => 'IMP-101']);
        $specA = CourseSpecification::factory()->create(['course_id' => $prereqA->id, 'credit_units' => 3.00, 'state' => CourseSpecification::StateActive]);
        $entryA = CurriculumEntry::factory()->create(['curriculum_version_id' => $curriculum->id, 'course_specification_id' => $specA->id]);

        $dependent = Course::factory()->create(['code' => 'IMP-102']);
        $specDep = CourseSpecification::factory()->create(['course_id' => $dependent->id, 'credit_units' => 3.00, 'state' => CourseSpecification::StateActive]);
        $entryDep = CurriculumEntry::factory()->create(['curriculum_version_id' => $curriculum->id, 'course_specification_id' => $specDep->id]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $specDep->id,
            'related_course_id' => $prereqA->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'g1',
            'state' => CourseRequirement::StateActive,
        ]);

        $term1 = Term::factory()->create(['starts_on' => now()->subMonths(3)->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'state' => Term::StateActive]);
        $attempt = $this->recordOfficialCourseAttempt($student, $term1, $entryA, $faculty, $registrar, '2.00', GradeRosterRow::CategoryPassing);
        $initialEvent = $attempt->gradeRosterRow->outcomeEvents()->sole();

        $nextTerm = Term::factory()->create(['starts_on' => now()->addMonths(2)->toDateString(), 'ends_on' => now()->addMonths(6)->toDateString(), 'state' => Term::StateActive]);
        $depOffering = TermOffering::factory()->create(['term_id' => $nextTerm->id, 'curriculum_entry_id' => $entryDep->id, 'state' => TermOffering::StateScheduled]);
        $nextCase = Enrollment::factory()->create([
            'credential_user_id' => $studentUser->id,
            'student_profile_id' => $student->id,
            'term_id' => $nextTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $nextCase->id,
            'term_offering_id' => $depOffering->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3.00,
            'added_at' => now(),
        ]);

        // 1. Passing initial release does NOT open a blocking manual impact review
        app(OpenRegistrationImpactReviewsForGradeOutcome::class)->execute($attempt->gradeRosterRow, $initialEvent, $registrar);
        $this->assertSame(0, $nextCase->registrationEvents()->where('event_type', 'AcademicResultImpactReviewOpened')->count());

        // 2. Passing -> passing correction (2.00 -> 1.75) opens NO review
        app(RecordApprovedGradeCorrection::class)->execute($attempt->gradeRosterRow, '1.75', 'Verified passing correction.', $registrar);
        $this->assertSame(0, $nextCase->registrationEvents()->where('event_type', 'AcademicResultImpactReviewOpened')->count());

        // 3. Passing -> failing correction (1.75 -> 5.00) invalidates prerequisite -> opens 1 impact review
        app(RecordApprovedGradeCorrection::class)->execute($attempt->gradeRosterRow, '5.00', 'Corrected to failing grade.', $registrar);
        $this->assertSame(1, $nextCase->registrationEvents()->where('event_type', 'AcademicResultImpactReviewOpened')->count());

        // 4. Failing -> passing correction (5.00 -> 2.00) opens NO second blocking review
        app(RecordApprovedGradeCorrection::class)->execute($attempt->gradeRosterRow, '2.00', 'Restored passing grade.', $registrar);
        $this->assertSame(1, $nextCase->registrationEvents()->where('event_type', 'AcademicResultImpactReviewOpened')->count());

        // 5. Later retake failing (5.00) when earlier passing attempt (2.00) still exists opens NO new review
        $term1b = Term::factory()->create(['starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'state' => Term::StateActive]);
        $retake = $this->recordOfficialCourseAttempt($student, $term1b, $entryA, $faculty, $registrar, '5.00', GradeRosterRow::CategoryFailed);
        app(OpenRegistrationImpactReviewsForGradeOutcome::class)->execute($retake->gradeRosterRow, $retake->gradeRosterRow->outcomeEvents()->sole(), $registrar);
        $this->assertSame(1, $nextCase->registrationEvents()->where('event_type', 'AcademicResultImpactReviewOpened')->count());
    }

    #[Test]
    public function lifecycle_finance_action_preserves_term_id_in_payload(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $student = StudentProfile::factory()->create(['user_id' => $studentUser->id]);
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'credential_user_id' => $studentUser->id,
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);

        $change = StudentLifecycleChange::query()->create([
            'student_profile_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'state' => StudentLifecycleChange::StateApplied,
            'from_lifecycle_state' => StudentProfile::LifecycleActive,
            'to_lifecycle_state' => StudentProfile::LifecycleWithdrawn,
            'effective_on' => today()->toDateString(),
            'reason' => 'Approved withdrawal.',
            'authority_reference' => 'WD-CP2-001',
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
            'applied_at' => now(),
        ]);

        $event = app(LifecycleFinanceAction::class)->execute($change, $registrar);

        $this->assertNotNull($event);
        $this->assertSame($term->id, data_get($event->payload, 'term_id'));
        $this->assertSame($enrollment->id, data_get($event->payload, 'enrollment_id'));
        $this->assertSame($student->id, data_get($event->payload, 'student_profile_id'));
    }

    #[Test]
    public function why_these_subjects_grouped_registrar_curriculum_progress_and_round_trip_links_work_for_students_and_registrar(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['name' => 'Diploma in Information Technology']);
        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'version_code' => 'DIT-2026-V1',
            'state' => CurriculumVersion::StateActive,
        ]);
        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $student = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
        ]);

        $course1 = Course::factory()->create(['code' => 'IT-101']);
        $spec1 = CourseSpecification::factory()->create(['course_id' => $course1->id, 'title' => 'Intro to Computing', 'credit_units' => 3.00, 'state' => CourseSpecification::StateActive]);
        $entry1 = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $curriculum->id,
            'course_specification_id' => $spec1->id,
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        $course2 = Course::factory()->create(['code' => 'IT-102']);
        $spec2 = CourseSpecification::factory()->create(['course_id' => $course2->id, 'title' => 'Programming 2', 'credit_units' => 3.00, 'state' => CourseSpecification::StateActive]);
        $entry2 = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $curriculum->id,
            'course_specification_id' => $spec2->id,
            'year_level' => 'First Year',
            'term_label' => 'Second Semester',
            'sequence' => 2,
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $spec2->id,
            'related_course_id' => $course1->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'g1',
            'state' => CourseRequirement::StateActive,
        ]);

        $term = Term::factory()->create(['label' => 'First Semester 2026', 'state' => Term::StateActive]);
        $offering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $entry1->id, 'state' => TermOffering::StateScheduled]);
        $section = Section::factory()->create(['term_offering_id' => $offering->id, 'code' => 'IT1A']);

        $case = Enrollment::factory()->create([
            'credential_user_id' => $studentUser->id,
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $timetable = PublishedTimetableVersion::factory()->create(['term_id' => $term->id]);
        $proposal = RegistrationProposalVersion::query()->create([
            'enrollment_id' => $case->id,
            'version' => 1,
            'state' => RegistrationProposalVersion::StateDraft,
            'purpose' => RegistrationProposalVersion::PurposeInitial,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'published_timetable_version_id' => $timetable->id,
            'curriculum_version_id' => $curriculum->id,
            'source_snapshot' => [
                'unit_load' => [
                    'year_level' => 'First Year',
                    'term_label' => 'First Semester',
                    'normal_total' => '18.00',
                    'requested_total' => '3.00',
                    'requires_graduating_overload' => false,
                ],
            ],
            'content_hash' => hash('sha256', 'cp2-test-proposal'),
            'prepared_by' => $registrar->id,
            'prepared_at' => now(),
        ]);
        RegistrationProposalItem::query()->create([
            'registration_proposal_version_id' => $proposal->id,
            'sequence' => 1,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'course_code_snapshot' => 'IT-101',
            'course_title_snapshot' => 'Intro to Computing',
            'units_snapshot' => 3.00,
            'scheduling_treatment_snapshot' => CourseSpecification::SchedulingRecurring,
            'meeting_snapshot' => [],
        ]);
        $case->update(['current_proposal_version_id' => $proposal->id]);

        // 1. Registrar ViewEnrollment renders "Why these subjects" + link to ViewStudentProfile (recordTab=academic & fromEnrollment)
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $profileUrl = StudentProfileResource::getUrl('view', [
            'record' => $student,
            'recordTab' => 'academic',
            'fromEnrollment' => $case->id,
        ]);
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSee('Why these subjects')
            ->assertSee('Individually advised')
            ->assertSee('DIT-2026-V1')
            ->assertSee('View academic record & curriculum progress')
            ->assertSee($profileUrl);

        // 2. Registrar ViewStudentProfile (recordTab=academic) groups Curriculum progress by Year & Term + prerequisite cue + return link
        $enrollmentUrl = EnrollmentResource::getUrl('view', ['record' => $case]);
        Livewire::actingAs($registrar)
            ->withQueryParams(['recordTab' => 'academic', 'fromEnrollment' => $case->id])
            ->test(ViewStudentProfile::class, ['record' => $student->id])
            ->assertSee('Curriculum progress')
            ->assertSee('Year 1')
            ->assertSee('First Semester')
            ->assertSee('Second Semester')
            ->assertSee('Prerequisite: IT-101')
            ->assertSee('Return to enrollment case')
            ->assertSee($enrollmentUrl);

        // 3. Student Enrollment renders "Why these subjects" + context-preserving link to Study Plan
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $studyPlanUrl = StudentAcademics::getUrl(['view' => 'plan', 'enrollmentTerm' => $term->id], panel: 'student');
        Livewire::actingAs($studentUser)
            ->withQueryParams(['term' => $term->id])
            ->test(StudentEnrollment::class)
            ->assertSee('Why these subjects')
            ->assertSee('Individually advised')
            ->assertSee('View full study plan')
            ->assertSee($studyPlanUrl);

        // 4. Student Study Plan preserves return link back to Enrollment with term
        $returnToEnrollmentUrl = StudentEnrollment::getUrl(['term' => $term->id], panel: 'student');
        Livewire::actingAs($studentUser)
            ->withQueryParams(['view' => 'plan', 'enrollmentTerm' => $term->id])
            ->test(StudentAcademics::class)
            ->assertSee('Return to enrollment')
            ->assertSee($returnToEnrollmentUrl);
    }

    #[Test]
    public function pre_student_applicant_omits_study_plan_link_and_sees_inline_explanation_and_transfer_credits(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['name' => 'Diploma in Tourism and Hospitality']);
        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'version_code' => 'DTHM-2026-V1',
            'state' => CurriculumVersion::StateActive,
        ]);
        $term = Term::factory()->create(['label' => 'First Semester 2026', 'state' => Term::StateActive]);
        $cycle = AdmissionCycle::factory()->create([
            'term_id' => $term->id,
            'state' => AdmissionCycle::StatePublished,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addWeek(),
        ]);

        $applicantUser = User::factory()->create(['status' => User::StatusActive]);
        $applicantUser->assignRole('applicant');
        $application = AdmissionApplication::factory()->create([
            'user_id' => $applicantUser->id,
            'admission_cycle_id' => $cycle->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'application_path' => AdmissionApplication::PathTransferee,
            'application_state' => AdmissionApplication::StateAdmitted,
        ]);
        $submission = ApplicationSubmissionVersion::factory()->create([
            'admission_application_id' => $application->id,
            'version' => 1,
        ]);
        $application->update(['current_submission_version_id' => $submission->id]);
        AdmissionDecision::factory()->create([
            'admission_application_id' => $application->id,
            'application_submission_version_id' => $submission->id,
            'decision' => AdmissionDecision::DecisionAdmitted,
        ]);

        // 1. Pre-case Applicant Enrollment MUST NOT render "View study plan"
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        Livewire::actingAs($applicantUser)
            ->test(ApplicantEnrollment::class)
            ->assertDontSee('View study plan')
            ->assertDontSee('/student/academics');

        // 2. With active transferee Registration Case + Transfer Credit Evaluation + Proposal
        $creditedCourse = Course::factory()->create(['code' => 'TR-101']);
        $creditedSpec = CourseSpecification::factory()->create(['course_id' => $creditedCourse->id, 'credit_units' => 3.00, 'state' => CourseSpecification::StateActive]);
        $creditedEntry = CurriculumEntry::factory()->create(['curriculum_version_id' => $curriculum->id, 'course_specification_id' => $creditedSpec->id]);

        $proposedCourse = Course::factory()->create(['code' => 'TR-102']);
        $proposedSpec = CourseSpecification::factory()->create(['course_id' => $proposedCourse->id, 'title' => 'Next Course', 'credit_units' => 3.00, 'state' => CourseSpecification::StateActive]);
        $proposedEntry = CurriculumEntry::factory()->create(['curriculum_version_id' => $curriculum->id, 'course_specification_id' => $proposedSpec->id]);
        $offering = TermOffering::factory()->create(['term_id' => $term->id, 'curriculum_entry_id' => $proposedEntry->id, 'state' => TermOffering::StateScheduled]);
        $section = Section::factory()->create(['term_offering_id' => $offering->id, 'code' => 'TR1A']);

        $case = Enrollment::factory()->create([
            'credential_user_id' => $applicantUser->id,
            'student_profile_id' => null,
            'admission_application_id' => $application->id,
            'term_id' => $term->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $creditEval = RegistrationCreditEvaluation::query()->create([
            'enrollment_id' => $case->id,
            'application_submission_version_id' => $submission->id,
            'curriculum_version_id' => $curriculum->id,
            'credited_curriculum_entry_ids' => [$creditedEntry->id],
            'authority_reference' => 'TOR-EVAL-2026-01',
            'approved_on' => today()->toDateString(),
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
        ]);
        $timetable = PublishedTimetableVersion::factory()->create(['term_id' => $term->id]);
        $proposal = RegistrationProposalVersion::query()->create([
            'enrollment_id' => $case->id,
            'version' => 1,
            'state' => RegistrationProposalVersion::StateDraft,
            'purpose' => RegistrationProposalVersion::PurposeInitial,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'published_timetable_version_id' => $timetable->id,
            'curriculum_version_id' => $curriculum->id,
            'source_snapshot' => [
                'credit_evaluation_id' => $creditEval->id,
            ],
            'content_hash' => hash('sha256', 'cp2-applicant-proposal'),
            'prepared_by' => $registrar->id,
            'prepared_at' => now(),
        ]);
        RegistrationProposalItem::query()->create([
            'registration_proposal_version_id' => $proposal->id,
            'sequence' => 1,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'course_code_snapshot' => 'TR-102',
            'course_title_snapshot' => 'Next Course',
            'units_snapshot' => 3.00,
            'scheduling_treatment_snapshot' => CourseSpecification::SchedulingRecurring,
            'meeting_snapshot' => [],
        ]);
        $case->update(['current_proposal_version_id' => $proposal->id]);

        $projection = app(EnrollmentProposalExplanationProjection::class)
            ->forEnrollment($case, $proposal, EnrollmentProposalExplanationProjection::AudienceApplicant);
        $this->assertFalse($projection['has_student_profile']);
        $this->assertNull($projection['evaluation_url']);
        $this->assertSame(['TR-101'], $projection['credited_course_codes']);

        // Applicant Enrollment shows inline explanation + transfer-credit facts, NO Study Plan link
        Livewire::actingAs($applicantUser)
            ->test(ApplicantEnrollment::class)
            ->assertSee('Why these subjects')
            ->assertSee('TR-101')
            ->assertSee('Transfer credit evaluation')
            ->assertDontSee('View study plan')
            ->assertDontSee('View full study plan');

        // Applicant Dashboard summary shows inline explanation + transfer-credit facts, NO Study Plan link
        Livewire::actingAs($applicantUser)
            ->test(ApplicantDashboard::class)
            ->assertSee('Why these subjects')
            ->assertSee('TR-101')
            ->assertDontSee('View study plan')
            ->assertDontSee('View full study plan');
    }

    private function recordOfficialCourseAttempt(
        StudentProfile $student,
        Term $term,
        CurriculumEntry $entry,
        User $faculty,
        User $registrar,
        ?string $resultCode,
        ?string $category,
        string $courseStatus = CourseEnrollment::StatusActive,
    ): CourseEnrollment {
        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->create(['term_offering_id' => $offering->id]);
        $enrollment = Enrollment::factory()->create([
            'credential_user_id' => $student->user_id,
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => $courseStatus,
            'is_current' => true,
            'units_snapshot' => (float) ($entry->courseSpecification?->credit_units ?? 3.00),
            'added_at' => now(),
        ]);

        if ($resultCode !== null && $category !== null) {
            $assignment = ClassOfferingTeachingAssignment::query()->create([
                'term_offering_id' => $offering->id,
                'section_id' => $section->id,
                'faculty_user_id' => $faculty->id,
                'role' => ClassOfferingTeachingAssignment::RoleDesignated,
                'state' => ClassOfferingTeachingAssignment::StateActive,
                'authority_reference' => 'CP2-ASSIGN-'.$courseEnrollment->id,
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
                'current_outcome_category' => $category,
                'released_at' => now(),
            ]);
            GradeOutcomeEvent::query()->create([
                'grade_roster_row_id' => $row->id,
                'source_key' => 'cp2-initial:'.$row->id,
                'event_type' => GradeOutcomeEvent::TypeInitialRelease,
                'result_code' => $resultCode,
                'source_term_ends_on' => $term->ends_on,
                'new_value' => is_numeric($resultCode) ? (float) $resultCode : null,
                'new_category' => $category,
                'reason' => 'Initial release',
                'recorded_by' => $registrar->id,
                'released_at' => now(),
            ]);
        }

        return $courseEnrollment->fresh(['gradeRosterRow.outcomeEvents']);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
