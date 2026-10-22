<?php

namespace Tests\Feature\Academics;

use App\Actions\Academics\AcademicAverageReadiness;
use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\ExaminationPeriodProjection;
use App\Actions\Academics\RecordAcademicDecision;
use App\Actions\Academics\RecordExternalCompetencyResult;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Grades\AmendIncDeadline;
use App\Actions\Grades\CurrentClassRosterOutput;
use App\Actions\Grades\FinalResultPolicy;
use App\Actions\Grades\IncDeadlineService;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\RecordApprovedGradeCorrection;
use App\Actions\Grades\ReleaseIncCompletion;
use App\Actions\Grades\ReturnGradeRoster;
use App\Actions\Grades\SaveFinalGradeResult;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SubmitIncCompletion;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Filament\Student\Pages\Academics as StudentAcademics;
use App\Mail\AcademicRecordChangedMail;
use App\Models\AcademicDecision;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\ExternalCompetencyRequirement;
use App\Models\ExternalCompetencyResult;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterVersion;
use App\Models\OperationalEvent;
use App\Models\OutputAccessLog;
use App\Models\ProgramShiftCreditEntry;
use App\Models\RegistrationCaseEvent;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OfficialRosterToStudentAcademicsJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleFaculty, User::StaffRoleAcademicHead] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function final_result_contract_is_exact_and_no_meeting_roster_releases_atomically(): void
    {
        $policy = app(FinalResultPolicy::class);
        $this->assertSame([
            '1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75',
            '3.00', '4.00', '5.00', 'INC',
        ], $policy->acceptedCodes());

        foreach (['P', '3.50', 'prelim', '90'] as $retiredValue) {
            try {
                $policy->normalize($retiredValue);
                $this->fail("Expected {$retiredValue} to be rejected.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $fixture = $this->fixture(termEndsOn: '2028-02-29');
        $assignment = app(ManageTeachingAssignment::class)->designate(
            $fixture['section'], $fixture['faculty'], $fixture['registrar'], 'REG-T03-ASSIGN-001',
        );
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $this->assertSame($assignment->id, $roster->teaching_assignment_id);
        $this->assertCount(1, $roster->rows);
        $this->assertDatabaseCount('section_meetings', 0);

        app(SaveFinalGradeResult::class)->execute(
            $roster->rows->sole(), 'INC', 'Complete the remaining practical evidence.', $fixture['faculty'],
        );
        $submitted = app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
        $released = app(PostAndReleaseGradeRoster::class)->execute($submitted, $fixture['registrar'], 'REG-G02-RELEASE-001');
        $event = $released->rows->sole()->outcomeEvents->sole();

        $this->assertSame(GradeRoster::StateReleased, $released->state);
        $this->assertSame('INC', $event->result_code);
        $this->assertSame('2029-02-28', $event->deadline->toDateString());
        $this->assertSame('2028-02-29', $event->source_term_ends_on->toDateString());
        $this->assertSame('Complete the remaining practical evidence.', $event->inc_completion_note);
        $this->assertSame(IncDeadlineService::StateCompletionOpen, app(IncDeadlineService::class)->state($event, Carbon::parse('2029-02-28', 'Asia/Manila')));
        $this->assertSame(IncDeadlineService::StateCompletionOverdue, app(IncDeadlineService::class)->state($event, Carbon::parse('2029-03-01', 'Asia/Manila')));

        app(PostAndReleaseGradeRoster::class)->execute($released, $fixture['registrar'], 'REG-G02-RELEASE-001');
        $this->assertSame(1, GradeOutcomeEvent::query()->where('grade_roster_row_id', $event->grade_roster_row_id)->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeGradeSubmissionRequiredEmail)->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeGradeRosterReleasedEmail)->count());
        $this->assertSame(2, OperationalEvent::query()->where('event_type', OperationalEvent::TypeIncReleasedEmail)->count());
    }

    #[Test]
    public function returned_rows_and_membership_changes_preserve_submitted_versions(): void
    {
        $fixture = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-002');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $row = $roster->rows->sole();
        app(SaveFinalGradeResult::class)->execute($row, '2.00', null, $fixture['faculty']);
        $submitted = app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
        app(ReturnGradeRoster::class)->execute($submitted, $fixture['registrar'], 'Recheck the source result.', [$row->id]);

        $this->assertNotNull($row->fresh()->returned_at);
        $this->assertSame(GradeRosterVersion::StateReturned, $submitted->versions()->sole()->state);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeGradeRosterReturnedEmail)->count());
        app(SaveFinalGradeResult::class)->execute($row, '2.25', null, $fixture['faculty']);
        $resubmitted = app(SubmitGradeRoster::class)->execute($submitted->fresh(), $fixture['faculty']);
        $this->assertSame(2, $resubmitted->current_version_number);
        $this->assertSame('2.00', $resubmitted->versions()->where('version_number', 1)->firstOrFail()->rows()->sole()->final_result);

        $this->addStudentToOffering($fixture);
        $synchronized = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $this->assertSame(GradeRoster::StateDraft, $synchronized->state);
        $this->assertCount(2, $synchronized->rows);
        $this->assertSame(GradeRosterVersion::StateInvalidated, $synchronized->versions()->where('version_number', 2)->firstOrFail()->state);
    }

    #[Test]
    public function incomplete_submission_and_designated_faculty_replacement_fail_closed(): void
    {
        $fixture = $this->fixture();
        $original = app(ManageTeachingAssignment::class)->designate(
            $fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGNMENT-ORIGINAL',
        );
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);

        try {
            app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
            $this->fail('A blank final-result row must block the complete roster submission.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('grade_roster_versions', 0);
            $this->assertSame(GradeRoster::StateDraft, $roster->fresh()->state);
        }

        app(SaveFinalGradeResult::class)->execute($roster->rows->sole(), '2.00', null, $fixture['faculty']);
        app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
        $replacementFaculty = $this->staff(User::StaffRoleFaculty);
        $replacement = app(ManageTeachingAssignment::class)->designate(
            $fixture['section'], $replacementFaculty, $fixture['registrar'], 'ASSIGNMENT-REPLACEMENT',
        );

        $this->assertSame(ClassOfferingTeachingAssignment::StateReplaced, $original->fresh()->state);
        $this->assertSame($replacement->id, $original->fresh()->replaced_by_assignment_id);
        $this->assertSame($replacement->id, $roster->fresh()->teaching_assignment_id);
        $this->assertSame(GradeRoster::StateDraft, $roster->fresh()->state);
        $this->assertSame(GradeRosterVersion::StateInvalidated, $roster->versions()->sole()->state);
    }

    #[Test]
    public function inc_completion_amendment_and_correction_append_successors(): void
    {
        $fixture = $this->fixture(termEndsOn: today()->subMonth()->toDateString());
        $nextCase = $this->pendingRegistrationCaseFor($fixture);
        $event = $this->releaseResult($fixture, 'INC', 'Complete the missing laboratory demonstration.');
        $this->assertImpactReviewCount($nextCase, 1);
        $submission = app(SubmitIncCompletion::class)->execute(
            $event, '2.50', 'Laboratory demonstration completed.', $fixture['faculty'],
        );
        $successor = app(ReleaseIncCompletion::class)->execute($submission, $fixture['registrar'], 'INC-COMPLETION-001');
        app(ReleaseIncCompletion::class)->execute($submission->fresh(), $fixture['registrar'], 'INC-COMPLETION-001');

        $this->assertSame($event->id, $successor->predecessor_event_id);
        $this->assertSame('INC', $event->fresh()->result_code);
        $this->assertSame(IncDeadlineService::StateResolved, app(IncDeadlineService::class)->state($event));
        $this->assertImpactReviewCount($nextCase, 2);

        $corrected = app(RecordApprovedGradeCorrection::class)->execute(
            $event->row, '2.25', 'CORRECTION-BOARD-001', 'Approved correction.', 'EVIDENCE-001', $fixture['registrar'],
        );
        $correction = $corrected->outcomeEvents()->latest('id')->firstOrFail();
        app(RecordApprovedGradeCorrection::class)->execute(
            $event->row, '2.25', 'CORRECTION-BOARD-001', 'Approved correction.', 'EVIDENCE-001', $fixture['registrar'],
        );
        $this->assertSame($successor->id, $correction->predecessor_event_id);
        $this->assertSame('2.25', $corrected->current_outcome_code);
        $this->assertSame(3, $corrected->outcomeEvents()->count());
        $this->assertImpactReviewCount($nextCase, 3);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeIncResolvedEmail)->count());
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeGradeCorrectionReleasedEmail)->count());

        Mail::fake();
        $overdueFixture = $this->fixture(termEndsOn: today()->subYears(2)->toDateString());
        $overdue = $this->releaseResult($overdueFixture, 'INC', 'Complete approved outstanding work.');
        Mail::assertQueuedCount(4);
        $this->assertSame(IncDeadlineService::StateCompletionOverdue, app(IncDeadlineService::class)->state($overdue));
        app(AmendIncDeadline::class)->execute(
            $overdue, today()->addMonth(), 'INC-EXTENSION-001', today(),
            'Approved extension based on documented circumstances.', $overdueFixture['registrar'],
        );
        $this->assertSame(IncDeadlineService::StateCompletionOpen, app(IncDeadlineService::class)->state($overdue));
        $this->assertSame('INC', $overdue->row->fresh()->current_outcome_code);
        Mail::assertQueuedCount(6);
        $this->assertSame(2, OperationalEvent::query()->where('event_type', OperationalEvent::TypeIncDeadlineAmendedEmail)->count());
    }

    #[Test]
    public function averages_exclude_pe_and_external_competency_supersedes_without_grade_effect(): void
    {
        $fixture = $this->fixture();
        $ordinary = $this->releaseResult($fixture, '2.25');
        $peFixture = $this->fixture(
            term: $fixture['term'], student: $fixture['student'],
            classification: CourseSpecification::AcademicClassificationPe,
        );
        $this->releaseResult($peFixture, '1.00');

        $termAverage = app(TermWeightedAverageProjection::class)->forStudentAndTerm($fixture['student'], $fixture['term']);
        $cumulative = app(CumulativeGwaProjection::class)->forStudent($fixture['student']);
        $this->assertSame(AcademicAverageReadiness::Available, $termAverage['state']);
        $this->assertSame('2.25', $termAverage['value']);
        $this->assertSame('2.25', $cumulative['value']);
        $this->assertSame(1, $cumulative['included_attempts']);

        $requirement = ExternalCompetencyRequirement::factory()->create([
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
            'state' => 'ACTIVE',
        ]);
        $action = app(RecordExternalCompetencyResult::class);
        $first = $action->execute(
            $requirement, $fixture['student'], ExternalCompetencyResult::OutcomeNotYetCompetent,
            'PRIVATE-ASSESSOR-001', 'REG-G06-001', today(), $fixture['registrar'],
            assessmentDate: today()->subDay(),
            externalSource: 'TESDA-accredited assessment center',
            credentialType: 'NC',
            credentialReference: 'NC-2026-001',
            credentialValidUntil: today()->addYears(5),
            safeRemarks: 'Assessment evidence verified by the Registrar.',
            commandKey: 'external-result-first',
        );
        $retry = $action->execute(
            $requirement, $fixture['student'], ExternalCompetencyResult::OutcomeNotYetCompetent,
            'PRIVATE-ASSESSOR-001', 'REG-G06-001', today(), $fixture['registrar'],
            assessmentDate: today()->subDay(),
            externalSource: 'TESDA-accredited assessment center',
            credentialType: 'NC',
            credentialReference: 'NC-2026-001',
            credentialValidUntil: today()->addYears(5),
            safeRemarks: 'Assessment evidence verified by the Registrar.',
            commandKey: 'external-result-first',
        );
        $this->assertSame($first->id, $retry->id);
        $second = $action->execute(
            $requirement, $fixture['student'], ExternalCompetencyResult::OutcomeCompetent,
            'PRIVATE-ASSESSOR-002', 'REG-G06-002', today(), $fixture['registrar'],
            expectedPredecessorId: $first->id,
            commandKey: 'external-result-second',
        );
        $this->assertFalse($first->fresh()->is_current);
        $this->assertSame($first->id, $second->supersedes_result_id);
        $this->assertSame('NC', $first->credential_type);
        $this->assertSame('NC-2026-001', $first->credential_reference);

        try {
            $action->execute(
                $requirement, $fixture['student'], ExternalCompetencyResult::OutcomeNotYetCompetent,
                'PRIVATE-ASSESSOR-003', 'REG-G06-003', today(), $fixture['registrar'],
                expectedPredecessorId: $first->id,
                commandKey: 'external-result-stale',
            );
            $this->fail('A stale external competency predecessor must be rejected.');
        } catch (RuntimeException) {
            $this->assertSame($second->id, ExternalCompetencyResult::query()->where('is_current', true)->where('student_profile_id', $fixture['student']->id)->sole()->id);
        }
        $this->assertSame('2.25', $ordinary->row->fresh()->current_outcome_code);

        $creditFixture = $this->fixture();
        $creditEntry = $creditFixture['course_enrollment']->termOffering->curriculumEntry;
        $programShift = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $creditFixture['student']->id,
            'term_id' => $creditFixture['term']->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        ProgramShiftCreditEntry::factory()->create([
            'student_lifecycle_change_id' => $programShift->id,
            'curriculum_entry_id' => $creditEntry->id,
            'source_course_id' => $creditEntry->courseSpecification->course_id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            'numeric_grade' => '2.00',
        ]);
        $evaluation = app(CurriculumEvaluation::class)->forStudent($creditFixture['student']);

        $this->assertSame('Approved credit', $evaluation['required'][0]['status']);
        $this->assertSame(0, $evaluation['deficiency_count']);
        $this->assertSame(number_format((float) $creditEntry->courseSpecification->credit_units, 2, '.', ''), $evaluation['completed_units']);
    }

    #[Test]
    public function cumulative_gwa_retains_retake_attempts_excludes_nstp_and_rounds_half_up_once(): void
    {
        $firstTerm = Term::factory()->create([
            'starts_on' => '2026-01-05',
            'ends_on' => '2026-05-31',
            'state' => Term::StateActive,
        ]);
        $first = $this->fixture(term: $firstTerm);
        $specification = $first['course_enrollment']->termOffering->curriculumEntry->courseSpecification;
        $this->releaseResult($first, '1.00');

        $secondTerm = Term::factory()->create([
            'starts_on' => '2026-08-03',
            'ends_on' => '2026-12-19',
            'state' => Term::StateActive,
        ]);
        $retake = $this->fixture(
            term: $secondTerm,
            student: $first['student'],
            specification: $specification,
        );
        $this->releaseResult($retake, '1.25');
        $nstp = $this->fixture(
            term: $secondTerm,
            student: $first['student'],
            classification: CourseSpecification::AcademicClassificationNstp,
        );
        $this->releaseResult($nstp, '1.00');

        $cumulative = app(CumulativeGwaProjection::class)->forStudent($first['student']);

        $this->assertSame(2, $cumulative['included_attempts']);
        $this->assertSame('1.13', $cumulative['value']);
        $this->assertSame($secondTerm->label, $cumulative['through']);
    }

    #[Test]
    public function readiness_academic_effect_and_authorized_decision_successors_are_deterministic(): void
    {
        $fixture = $this->fixture(termEndsOn: today()->subMonth()->toDateString());
        $unreleased = app(TermWeightedAverageProjection::class)->forStudentAndTerm($fixture['student'], $fixture['term']);
        $this->assertSame(AcademicAverageReadiness::GradesNotComplete, $unreleased['state']);
        $this->assertNull($unreleased['value']);

        $this->releaseResult($fixture, 'INC', 'Complete the remaining authorized work.');
        $incomplete = app(TermWeightedAverageProjection::class)->forStudentAndTerm($fixture['student'], $fixture['term']);
        $this->assertSame(AcademicAverageReadiness::IncompleteResultPending, $incomplete['state']);
        $this->assertSame(AcademicDecision::EffectAdvisingRequired, app(AcademicEnrollmentEffect::class)->forStudent($fixture['student'])['effect']);

        $first = app(RecordAcademicDecision::class)->execute(
            $fixture['student'], $fixture['term'], AcademicDecision::EffectBlocked,
            'ACADEMIC-BOARD-001', today(), 'An authorized decision blocks the consuming action.',
            today(), null, $fixture['registrar'],
        );
        $this->assertSame(AcademicDecision::EffectBlocked, app(AcademicEnrollmentEffect::class)->forStudent($fixture['student'], $fixture['term'])['effect']);

        $second = app(RecordAcademicDecision::class)->execute(
            $fixture['student'], $fixture['term'], AcademicDecision::EffectPendingDecision,
            'ACADEMIC-BOARD-002', today(), 'A named institutional review remains open.',
            today(), null, $fixture['registrar'],
        );
        $this->assertSame('SUPERSEDED', $first->fresh()->state);
        $this->assertSame('ACTIVE', $second->fresh()->state);
        $this->assertSame(AcademicDecision::EffectPendingDecision, app(AcademicEnrollmentEffect::class)->forStudent($fixture['student'], $fixture['term'])['effect']);

        $peFixture = $this->fixture(classification: CourseSpecification::AcademicClassificationPe);
        $this->releaseResult($peFixture, '1.00');
        $notApplicable = app(TermWeightedAverageProjection::class)->forStudentAndTerm($peFixture['student'], $peFixture['term']);
        $this->assertSame(AcademicAverageReadiness::NotApplicable, $notApplicable['state']);
        $this->assertNull($notApplicable['value']);
    }

    #[Test]
    public function all_owning_roles_receive_the_same_exact_term_examination_period_projection(): void
    {
        $fixture = $this->fixture();
        $fixture['student']->user?->assignRole('student');
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $package = TermCalendarPackage::query()->where('term_id', $fixture['term']->id)->where('state', TermCalendarPackage::StateActive)->sole();
        $package->update([
            'version' => 3,
            'state' => TermCalendarPackage::StateActive,
            'authority_reference' => 'BOARD-CALENDAR-2026-03',
            'authority_date' => today(),
        ]);
        TermCalendarWindow::factory()->create([
            'term_calendar_package_id' => $package->id,
            'window_type' => TermCalendarWindow::TypeExaminationPeriod,
            'opens_on' => '2027-05-10',
            'closes_on' => '2027-05-15',
        ]);
        app(ManageTeachingAssignment::class)->designate(
            $fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-EXAM-001',
        );
        app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);

        $projection = app(ExaminationPeriodProjection::class)->forTerm($fixture['term']);
        $this->assertSame('Available', $projection['status']);
        $this->assertSame('BOARD-CALENDAR-2026-03', $projection['authority_reference']);
        $this->assertSame('2027-05-10', $projection['opens_on']->toDateString());
        $this->assertSame('2027-05-15', $projection['closes_on']->toDateString());
        $this->assertSame($projection['authority_reference'], app(ExaminationPeriodProjection::class)->latest()['authority_reference']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach ([
            [$fixture['faculty'], FacultyGradeRoster::class],
            [$fixture['registrar'], GradesAndCompletion::class],
            [$academicHead, AcademicApprovals::class],
        ] as [$actor, $page]) {
            Livewire::actingAs($actor)->test($page)
                ->assertSee('Examination Period')
                ->assertSee('May 10, 2027')
                ->assertSee('May 15, 2027')
                ->assertSee('BOARD-CALENDAR-2026-03')
                ->assertSee('Class-level examination arrangements are not inferred.');
        }

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($fixture['student']->user)->test(StudentAcademics::class)
            ->assertSee('Exam dates')
            ->assertSee('Calendar details')
            ->assertSee('May 10, 2027')
            ->assertSee('May 15, 2027')
            ->assertSee('BOARD-CALENDAR-2026-03')
            ->assertSee('Class-level examination arrangements are not inferred.');
    }

    #[Test]
    public function faculty_roster_access_is_limited_to_exact_assigned_sections_and_locked_selection(): void
    {
        $fixture = $this->fixture();
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        $siblingFaculty = $this->staff(User::StaffRoleFaculty);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-ISOLATION-MAIN');
        app(ManageTeachingAssignment::class)->addCoFaculty($fixture['section'], $coFaculty, $fixture['registrar'], 'ASSIGN-ISOLATION-CO');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $siblingSection = Section::factory()->create([
            'term_offering_id' => $fixture['section']->term_offering_id,
            'state' => Section::StateOpen,
        ]);
        $siblingEnrollment = Enrollment::factory()->create([
            'term_id' => $fixture['term']->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        CourseEnrollment::factory()->create([
            'enrollment_id' => $siblingEnrollment->id,
            'term_offering_id' => $siblingSection->term_offering_id,
            'section_id' => $siblingSection->id,
            'published_timetable_version_id' => null,
        ]);
        app(ManageTeachingAssignment::class)->designate($siblingSection, $siblingFaculty, $fixture['registrar'], 'ASSIGN-ISOLATION-SIBLING');
        $siblingRoster = app(SynchronizeOfficialGradeRoster::class)->execute($siblingSection, $fixture['registrar']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($coFaculty)->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', $roster->id)
            ->assertSet('rosterEditable', false)
            ->assertActionHidden('selectRoster');
        $this->assertSame([$fixture['student']->student_number], array_column($page->get('data.rows'), 'student_number'));

        Livewire::actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', $roster->id)
            ->assertSet('rosterEditable', true);

        foreach ([$fixture['faculty'], $coFaculty] as $faculty) {
            $faculty->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
            $faculty->saveAppAuthenticationRecoveryCodes(['stored-code']);
            Livewire::actingAs($faculty)->test(ListGradeRosters::class)
                ->assertCanSeeTableRecords([$roster])
                ->assertCanNotSeeTableRecords([$siblingRoster]);
            $this->actingAs($faculty)->get(GradeRosterResource::getUrl('view', ['record' => $siblingRoster]))->assertNotFound();
        }

        $unrelatedFaculty = $this->staff(User::StaffRoleFaculty);
        Livewire::actingAs($unrelatedFaculty)->test(ListGradeRosters::class)
            ->assertCanNotSeeTableRecords([$roster, $siblingRoster]);
        Livewire::actingAs($unrelatedFaculty)->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', null)
            ->assertSet('data.rows', []);

        foreach ([$fixture['registrar'], $this->staff(User::StaffRoleAcademicHead)] as $staff) {
            Livewire::actingAs($staff)->test(ListGradeRosters::class)
                ->assertCanSeeTableRecords([$roster, $siblingRoster]);
        }

        $this->actingAs($coFaculty)->get(FacultyGradeRoster::getUrl(['roster' => $siblingRoster->id]))->assertNotFound();

        app(ManageTeachingAssignment::class)->addCoFaculty($siblingSection, $coFaculty, $fixture['registrar'], 'ASSIGN-ISOLATION-SECOND');
        Livewire::actingAs($coFaculty)->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', null)
            ->assertSee('Choose a class')
            ->assertSee($roster->section->code)
            ->assertSee($siblingRoster->section->code)
            ->assertSee(FacultyGradeRoster::getUrl(['roster' => $roster->id]), escape: false);

        Livewire::withQueryParams(['roster' => $roster->id])->actingAs($coFaculty)->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', $roster->id);
        Livewire::withQueryParams([]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($coFaculty)->test(FacultyGradeRoster::class)->set('rosterId', $siblingRoster->id);
    }

    #[Test]
    public function roster_and_unofficial_outputs_are_current_private_logged_and_formula_safe(): void
    {
        $fixture = $this->fixture();
        $fixture['student']->user?->assignRole('student');
        $fixture['student']->update(['last_name' => '=HYPERLINK("https://example.invalid")']);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-OUTPUT-001');
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        app(ManageTeachingAssignment::class)->addCoFaculty($fixture['section'], $coFaculty, $fixture['registrar'], 'ASSIGN-OUTPUT-002');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);

        $this->actingAs($coFaculty)
            ->get(route('grade-rosters.print', $roster))
            ->assertOk()
            ->assertSee('Current Class Roster')
            ->assertDontSee('Released result');

        $csv = $this->actingAs($fixture['faculty'])->get(route('grade-rosters.csv', $roster));
        $csv->assertOk();
        $this->assertMatchesRegularExpression(
            '/attachment; filename=tala-class-roster-[a-z0-9-]+-\d{8}-\d{6}-PHT\.csv/',
            (string) $csv->headers->get('content-disposition'),
        );
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->streamedContent());
        $lines = explode("\r\n", substr($csv->streamedContent(), 3));
        $this->assertSame(['Term', 'Class Reference', 'Course Code', 'Course Title', 'Program or Cohort', 'Student Number', 'Legal Name', 'As of (Asia/Manila)'], str_getcsv($lines[0], ',', '"', ''));
        $this->assertCount(8, str_getcsv($lines[1], ',', '"', ''));
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} PHT/', $csv->streamedContent());
        $this->assertStringContainsString("'=HYPERLINK", $csv->streamedContent());
        $this->assertStringContainsString("\r\n", $csv->streamedContent());

        $unrelatedFaculty = $this->staff(User::StaffRoleFaculty);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $this->actingAs($unrelatedFaculty)->get(route('grade-rosters.print', $roster))->assertForbidden();
        $this->actingAs($academicHead)->get(route('grade-rosters.print', $roster))->assertForbidden();
        $this->actingAs($academicHead)->get(route('grade-rosters.csv', $roster))->assertForbidden();
        $this->assertSame(2, OutputAccessLog::query()->where('source_record_type', GradeRoster::class)->where('source_record_id', $roster->id)->count());

        $this->actingAs($fixture['registrar'])->get(route('grade-rosters.print', $roster))->assertOk();
        $this->assertSame(3, OutputAccessLog::query()->where('source_record_type', GradeRoster::class)->where('source_record_id', $roster->id)->count());

        app(SaveFinalGradeResult::class)->execute($roster->rows->sole(), '2.00', null, $fixture['faculty']);
        app(PostAndReleaseGradeRoster::class)->execute(
            app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']),
            $fixture['registrar'],
            'REG-G02-OUTPUT-001',
        );
        $this->actingAs($fixture['student']->user)
            ->get(route('student-academics.unofficial-record', $fixture['student']))
            ->assertOk()
            ->assertSee('UNOFFICIAL STUDENT RECORD')
            ->assertSee('Academic progress');

        $otherStudent = StudentProfile::factory()->create();
        $otherStudent->user?->assignRole('student');
        $this->actingAs($otherStudent->user)
            ->get(route('student-academics.unofficial-record', $fixture['student']))
            ->assertForbidden();
        $this->actingAs($fixture['registrar'])
            ->get(route('student-academics.unofficial-record', $fixture['student']))
            ->assertForbidden();
        $this->actingAs($academicHead)
            ->get(route('student-academics.unofficial-record', $fixture['student']))
            ->assertForbidden();
        $this->assertSame(1, OutputAccessLog::query()
            ->where('output_type', 'Unofficial Student Record')
            ->where('source_record_id', $fixture['student']->id)
            ->where('status', 'generated')
            ->count());
    }

    #[Test]
    public function successful_academic_mail_records_transport_and_attempt_evidence(): void
    {
        $fixture = $this->fixture();
        $fixture['student']->user?->assignRole('student');
        $event = $this->releaseResult($fixture, '2.75');
        $notification = OperationalEvent::query()
            ->where('related_record_type', GradeOutcomeEvent::class)
            ->where('related_record_id', $event->id)
            ->where('event_type', OperationalEvent::TypeGradeRosterReleasedEmail)
            ->sole()
            ->fresh();

        $this->assertSame(OperationalEvent::StatusProcessed, $notification->status);
        $this->assertNotNull($notification->sent_at);
        $this->assertNotEmpty(data_get($notification->payload, 'delivery.transport_message_id'));
        $this->assertSame(OperationalEvent::StatusProcessed, data_get($notification->payload, 'delivery_attempts.0.status'));
        $this->assertNotEmpty(data_get($notification->payload, 'delivery_attempts.0.attempt_id'));
    }

    #[Test]
    public function academic_record_mail_is_value_free_resendable_and_cannot_roll_back_release(): void
    {
        Mail::fake();
        $fixture = $this->fixture();
        $fixture['student']->user?->assignRole('student');
        $event = $this->releaseResult($fixture, '2.75');
        $notification = OperationalEvent::query()
            ->where('related_record_type', GradeOutcomeEvent::class)
            ->where('related_record_id', $event->id)
            ->sole();
        $notification->update(['status' => OperationalEvent::StatusFailed]);

        app(AcademicRecordNotificationService::class)->resend($notification, $fixture['student']->user);
        Mail::assertQueued(AcademicRecordChangedMail::class, function (AcademicRecordChangedMail $mail): bool {
            return $mail->attachments() === []
                && ! str_contains($mail->changeLabel, '2.75')
                && ! str_contains($mail->changeLabel, 'grade value');
        });
        $this->assertCount(2, $notification->fresh()->payload['delivery_attempts']);

        $fixture['student']->user?->update(['email' => 'invalid-recipient']);
        $notification->refresh()->update(['status' => OperationalEvent::StatusFailed]);
        app(AcademicRecordNotificationService::class)->resend($notification->fresh(), $fixture['student']->user->fresh());

        $this->assertSame(OperationalEvent::StatusFailed, $notification->fresh()->status);
        $this->assertSame(OperationalEvent::StatusFailed, data_get($notification->fresh()->payload, 'delivery_attempts.2.status'));
        $this->assertSame('2.75', $event->row->fresh()->current_outcome_code);
        $this->assertSame(GradeRoster::StateReleased, $event->row->roster->fresh()->state);
    }

    #[Test]
    public function inc_calculates_leap_year_boundaries_permits_amendments_and_provides_retake_guidance(): void
    {
        $fixture = $this->fixture(termEndsOn: '2028-02-29');
        $incEvent = $this->releaseResult($fixture, 'INC', 'Complete laboratory demonstration.');
        $this->assertSame('2028-02-29', $incEvent->source_term_ends_on->toDateString());
        $this->assertSame('2029-02-28', $incEvent->deadline->toDateString());
        $this->assertSame(IncDeadlineService::StateCompletionOpen, app(IncDeadlineService::class)->state($incEvent, Carbon::parse('2029-02-28', 'Asia/Manila')));
        $this->assertSame(IncDeadlineService::StateCompletionOverdue, app(IncDeadlineService::class)->state($incEvent, Carbon::parse('2029-03-01', 'Asia/Manila')));

        app(AmendIncDeadline::class)->execute(
            $incEvent, today()->addMonths(6), 'INC-EXT-LEAP-001', today(),
            'Documented extension approval.', $fixture['registrar'],
        );
        $this->assertSame(IncDeadlineService::StateCompletionOpen, app(IncDeadlineService::class)->state($incEvent));

        $projection = app(CompletionReadinessProjection::class)->forStudent($fixture['student']);
        $incBlocker = collect($projection['blockers'])->firstWhere('code', 'official-result:inc-unresolved');
        $this->assertNotNull($incBlocker);
        $this->assertSame('Complete the authorized INC path or retake the course when completion is closed.', $incBlocker['recovery']);
    }

    #[Test]
    public function stale_inc_completion_is_blocked_by_intervening_correction_deadline_change_or_expiry(): void
    {
        // Scenario A: Intervening correction blocks release of earlier INC submission
        $fixtureA = $this->fixture(termEndsOn: today()->subMonth()->toDateString());
        $incEventA = $this->releaseResult($fixtureA, 'INC', 'Complete laboratory requirements.');
        $submissionA = app(SubmitIncCompletion::class)->execute(
            $incEventA, '2.25', 'Completed lab requirements.', $fixtureA['faculty'],
        );
        app(RecordApprovedGradeCorrection::class)->execute(
            $incEventA->row, '2.00', 'CORR-INTERVENING-001', 'Correction while submission pending.', 'EVID-001', $fixtureA['registrar'],
        );
        try {
            app(ReleaseIncCompletion::class)->execute($submissionA, $fixtureA['registrar'], 'INC-STALE-RELEASE');
            $this->fail('Intervening correction must block stale INC completion release.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stale because the result or deadline authority changed', $e->getMessage());
        }

        // Scenario B: Deadline change after submission blocks release
        $fixtureB = $this->fixture(termEndsOn: today()->subMonth()->toDateString());
        $incEventB = $this->releaseResult($fixtureB, 'INC', 'Complete practical requirements.');
        $submissionB = app(SubmitIncCompletion::class)->execute(
            $incEventB, '2.25', 'Completed practicals.', $fixtureB['faculty'],
        );
        app(AmendIncDeadline::class)->execute(
            $incEventB, today()->addMonths(3), 'AMEND-INTERVENING-001', today(),
            'Intervening extension.', $fixtureB['registrar'],
        );
        try {
            app(ReleaseIncCompletion::class)->execute($submissionB, $fixtureB['registrar'], 'INC-STALE-RELEASE');
            $this->fail('Intervening deadline amendment must block stale INC completion release.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('stale because the result or deadline authority changed', $e->getMessage());
        }

        // Scenario C: Expiry blocks submission without valid extension
        $fixtureC = $this->fixture(termEndsOn: today()->subYears(2)->toDateString());
        $overdueEvent = $this->releaseResult($fixtureC, 'INC', 'Overdue course requirement.');
        $this->assertSame(IncDeadlineService::StateCompletionOverdue, app(IncDeadlineService::class)->state($overdueEvent));
        try {
            app(SubmitIncCompletion::class)->execute($overdueEvent, '2.00', 'Late submission.', $fixtureC['faculty']);
            $this->fail('Expired INC deadline must block completion submission.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('INC completion is not open', $e->getMessage());
        }

        // Scenario D: Repeated/concurrent release on an already released submission is idempotent
        $fixtureD = $this->fixture(termEndsOn: today()->subMonth()->toDateString());
        $incEventD = $this->releaseResult($fixtureD, 'INC', 'Complete project.');
        $submissionD = app(SubmitIncCompletion::class)->execute(
            $incEventD, '2.00', 'Project completed.', $fixtureD['faculty'],
        );
        $releasedD1 = app(ReleaseIncCompletion::class)->execute($submissionD, $fixtureD['registrar'], 'INC-REL-D1');
        $this->assertSame('2.00', $releasedD1->result_code);

        // Duplicate/concurrent release returns the identical event without extra outcome rows
        $releasedD2 = app(ReleaseIncCompletion::class)->execute($submissionD, $fixtureD['registrar'], 'INC-REL-D2');
        $this->assertSame($releasedD1->id, $releasedD2->id);
        $this->assertSame(2, $incEventD->row->fresh()->outcomeEvents()->count());
    }

    #[Test]
    public function grade_correction_a_to_b_to_a_preserves_three_linked_events_with_idempotent_duplicate_commands(): void
    {
        $fixture = $this->fixture();
        $initialEvent = $this->releaseResult($fixture, '2.00');

        $correctedB = app(RecordApprovedGradeCorrection::class)->execute(
            $initialEvent->row, '2.25', 'CORR-A-TO-B-001', 'Change from 2.00 to 2.25.', 'EVID-B-001', $fixture['registrar'],
        );
        $eventB = $correctedB->outcomeEvents()->latest('id')->firstOrFail();
        $this->assertSame($initialEvent->id, $eventB->predecessor_event_id);
        $this->assertSame('2.25', $correctedB->fresh()->current_outcome_code);

        // Duplicate command for B is idempotent
        app(RecordApprovedGradeCorrection::class)->execute(
            $initialEvent->row, '2.25', 'CORR-A-TO-B-001', 'Change from 2.00 to 2.25.', 'EVID-B-001', $fixture['registrar'],
        );
        $this->assertSame(2, $initialEvent->row->fresh()->outcomeEvents()->count());

        // Correction back to A (2.00)
        $correctedA2 = app(RecordApprovedGradeCorrection::class)->execute(
            $initialEvent->row, '2.00', 'CORR-B-TO-A-001', 'Change back from 2.25 to 2.00.', 'EVID-A-002', $fixture['registrar'],
        );
        $eventA2 = $correctedA2->outcomeEvents()->latest('id')->firstOrFail();
        $this->assertSame($eventB->id, $eventA2->predecessor_event_id);
        $this->assertNotSame($initialEvent->id, $eventA2->id);
        $this->assertSame('2.00', $correctedA2->fresh()->current_outcome_code);

        $events = $initialEvent->row->fresh()->outcomeEvents()->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame($initialEvent->id, $events[0]->id);
        $this->assertSame($eventB->id, $events[1]->id);
        $this->assertSame($eventA2->id, $events[2]->id);
        $this->assertSame($initialEvent->id, $events[1]->predecessor_event_id);
        $this->assertSame($eventB->id, $events[2]->predecessor_event_id);

        // Duplicate command for A2 is idempotent
        app(RecordApprovedGradeCorrection::class)->execute(
            $initialEvent->row, '2.00', 'CORR-B-TO-A-001', 'Change back from 2.25 to 2.00.', 'EVID-A-002', $fixture['registrar'],
        );
        $this->assertCount(3, $initialEvent->row->fresh()->outcomeEvents()->get());
    }

    #[Test]
    public function empty_and_changed_roster_sources_fail_closed_without_partial_artifacts(): void
    {
        Storage::fake('local');
        $fixture = $this->fixture();
        $emptyOffering = TermOffering::factory()->create(['term_id' => $fixture['term']->id]);
        $emptySection = Section::factory()->create(['term_offering_id' => $emptyOffering->id, 'state' => Section::StateOpen]);
        app(ManageTeachingAssignment::class)->designate($emptySection, $fixture['faculty'], $fixture['registrar'], 'ASSIGN-EMPTY-001');
        $emptyRoster = app(SynchronizeOfficialGradeRoster::class)->execute($emptySection, $fixture['registrar']);

        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.print', $emptyRoster))->assertStatus(409);
        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.csv', $emptyRoster))->assertStatus(409);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::withQueryParams(['roster' => $emptyRoster->id])->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', $emptyRoster->id)->assertActionHidden('printRoster')->assertActionHidden('downloadRoster')
            ->assertActionHidden('submit')->assertDontSee('Save draft')->assertSee('No students enrolled yet');
        Livewire::withQueryParams([]);
        Livewire::actingAs($fixture['registrar'])->test(ViewGradeRoster::class, ['record' => $emptyRoster->id])
            ->assertActionHidden('exportRoster');

        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-MAIN-001');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $otherStudent = StudentProfile::factory()->create();
        $otherEnrollment = Enrollment::factory()->create(['student_profile_id' => $otherStudent->id, 'term_id' => $fixture['term']->id, 'status' => 'officially_enrolled', 'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $otherEnrollment->id,
            'term_offering_id' => $fixture['section']->term_offering_id,
            'section_id' => $fixture['section']->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);

        $this->actingAs($fixture['registrar'])->get(route('grade-rosters.print', $roster))->assertStatus(409);
        $this->actingAs($fixture['registrar'])->get(route('grade-rosters.csv', $roster))->assertStatus(409);

        // Inaccessible roster sources fail closed with 403
        $unauthorizedStudent = StudentProfile::factory()->create();
        $unauthorizedStudent->user?->assignRole('student');
        $this->actingAs($unauthorizedStudent->user)->get(route('grade-rosters.print', $roster))->assertForbidden();
        $this->actingAs($unauthorizedStudent->user)->get(route('grade-rosters.csv', $roster))->assertForbidden();

        $unassignedFaculty = $this->staff(User::StaffRoleFaculty);
        $this->actingAs($unassignedFaculty)->get(route('grade-rosters.print', $roster))->assertForbidden();
        $this->actingAs($unassignedFaculty)->get(route('grade-rosters.csv', $roster))->assertForbidden();

        // Failed roster sources (e.g. rendering exception or source failure) fail closed without partial artifacts
        $failOffering = TermOffering::factory()->create(['term_id' => $fixture['term']->id]);
        $failSection = Section::factory()->create(['term_offering_id' => $failOffering->id, 'state' => Section::StateOpen]);
        app(ManageTeachingAssignment::class)->designate($failSection, $fixture['faculty'], $fixture['registrar'], 'ASSIGN-FAIL-001');
        CourseEnrollment::query()->create([
            'enrollment_id' => $fixture['course_enrollment']->enrollment_id,
            'term_offering_id' => $failOffering->id,
            'section_id' => $failSection->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        $failingRoster = app(SynchronizeOfficialGradeRoster::class)->execute($failSection, $fixture['registrar']);

        view()->composer('outputs.class-roster', function (): void {
            throw new RuntimeException('Simulated failed roster source during rendering.');
        });

        try {
            $this->withoutExceptionHandling()->actingAs($fixture['faculty'])->get(route('grade-rosters.print', $failingRoster));
            $this->fail('Failed roster source must fail print action.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failed roster source during rendering.', $e->getMessage());
        }

        $this->assertDatabaseMissing('output_access_logs', [
            'source_record_type' => GradeRoster::class,
            'source_record_id' => $failingRoster->id,
            'status' => 'generated',
        ]);

        $this->assertEmpty(
            collect(Storage::disk('local')->allFiles())->filter(fn (string $file) => str_contains($file, 'roster') || str_ends_with($file, '.tmp')),
            'No partial roster artifacts must exist in storage following a failed print generation.'
        );

        // Failed roster source during CSV export fails closed without partial artifacts or access logs
        $failCsv = true;
        Enrollment::retrieved(function () use (&$failCsv): void {
            if ($failCsv) {
                throw new RuntimeException('Simulated failed roster source during CSV export.');
            }
        });

        try {
            $this->withoutExceptionHandling()->actingAs($fixture['faculty'])->get(route('grade-rosters.csv', $failingRoster));
            $this->fail('Failed roster source must fail CSV export action.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failed roster source during CSV export.', $e->getMessage());
        } finally {
            $failCsv = false;
        }

        $this->assertDatabaseMissing('output_access_logs', [
            'source_record_type' => GradeRoster::class,
            'source_record_id' => $failingRoster->id,
            'action' => 'download',
        ]);

        $this->assertEmpty(
            collect(Storage::disk('local')->allFiles())->filter(fn (string $file) => str_contains($file, 'roster') || str_ends_with($file, '.tmp') || str_ends_with($file, '.csv')),
            'No partial roster CSV artifacts must exist in storage following a failed export.'
        );

        // Stale roster sources (replaced/ended teaching assignment) produce no print/export action or partial artifact
        $this->withExceptionHandling();
        ClassOfferingTeachingAssignment::query()
            ->where('section_id', $failSection->id)
            ->update(['state' => ClassOfferingTeachingAssignment::StateReplaced]);

        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.print', $failingRoster))->assertForbidden();
        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.csv', $failingRoster))->assertForbidden();

        $this->assertDatabaseMissing('output_access_logs', [
            'source_record_type' => GradeRoster::class,
            'source_record_id' => $failingRoster->id,
            'status' => 'generated',
        ]);
        $this->assertEmpty(
            collect(Storage::disk('local')->allFiles())->filter(fn (string $file) => str_contains($file, 'roster') || str_ends_with($file, '.tmp')),
            'No partial roster artifacts must exist in storage for stale roster sources.'
        );
    }

    #[Test]
    public function class_roster_preview_and_csv_share_current_legal_identity_order_without_grade_or_finance_fields(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 10:00:00', 'Asia/Manila'));
        $fixture = $this->fixture();
        $fixture['student']->update(['last_name' => 'Santos', 'first_name' => 'Mira', 'middle_name' => 'B', 'student_number' => 'ROSTER-003']);
        foreach ([['Santos', 'Mira', 'A', 'ROSTER-010'], ['Cruz', 'Ana', null, 'ROSTER-001'], ['Santos', 'Mira', 'A', 'ROSTER-002']] as [$last, $first, $middle, $number]) {
            $student = StudentProfile::factory()->create(['last_name' => $last, 'first_name' => $first, 'middle_name' => $middle, 'student_number' => $number]);
            $enrollment = Enrollment::factory()->create(['student_profile_id' => $student->id,
                'term_id' => $fixture['term']->id, 'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
            CourseEnrollment::query()->create(['enrollment_id' => $enrollment->id,
                'term_offering_id' => $fixture['section']->term_offering_id, 'section_id' => $fixture['section']->id,
                'status' => CourseEnrollment::StatusActive, 'is_current' => true, 'units_snapshot' => '3.00', 'added_at' => now()]);
        }
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-PREVIEW-001');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $roster->rows()->update(['final_result' => 'INC', 'inc_completion_note' => 'PRIVATE-GRADE-NOTE']);

        $preview = app(CurrentClassRosterOutput::class)->preview($roster, $fixture['faculty']);
        $response = $this->actingAs($fixture['registrar'])->get(route('grade-rosters.csv', $roster))->assertOk();
        $lines = explode("\r\n", substr($response->streamedContent(), 3));
        $headers = str_getcsv(array_shift($lines), ',', '"', '');
        $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter($lines)));

        $this->assertSame(4, $preview['count']);
        $this->assertSame($preview['headers'], $headers);
        $this->assertSame($preview['rows'], $rows);
        $this->assertSame(['Cruz, Ana', 'Santos, Mira A', 'Santos, Mira A', 'Santos, Mira B'], array_column($rows, 6));
        $this->assertSame(['ROSTER-001', 'ROSTER-002', 'ROSTER-010', 'ROSTER-003'], array_column($rows, 5));
        $this->assertSame('2026-10-18 10:00:00 PHT', $rows[0][7]);
        $this->assertStringNotContainsString('PRIVATE-GRADE-NOTE', $response->streamedContent());
        $this->assertCount(8, $headers);
        $this->assertSame(1, OutputAccessLog::query()->where('source_record_type', GradeRoster::class)->where('source_record_id', $roster->id)->count());

        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.print', $roster))
            ->assertSeeInOrder(['Cruz, Ana', 'Santos, Mira A', 'Santos, Mira A', 'Santos, Mira B'])
            ->assertDontSee('Santos, Mira, B')
            ->assertDontSee('PRIVATE-GRADE-NOTE');
    }

    #[Test]
    public function class_roster_requires_canonical_official_membership_and_rechecks_changed_and_retained_rows(): void
    {
        $fixture = $this->fixture();
        $student = StudentProfile::factory()->create(['student_number' => 'LEGACY-ONLY']);
        $enrollment = Enrollment::factory()->create(['student_profile_id' => $student->id,
            'term_id' => $fixture['term']->id, 'status' => 'officially_enrolled', 'canonical_outcome' => Enrollment::OutcomeInProgress]);
        $course = CourseEnrollment::query()->create(['enrollment_id' => $enrollment->id,
            'term_offering_id' => $fixture['section']->term_offering_id, 'section_id' => $fixture['section']->id,
            'status' => CourseEnrollment::StatusActive, 'is_current' => true, 'units_snapshot' => '3.00', 'added_at' => now()]);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-STRICT-001');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $service = app(CurrentClassRosterOutput::class);
        $this->assertSame(1, $service->forRoster($roster, $fixture['faculty'])['count']);

        $enrollment->update(['canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);
        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.csv', $roster))->assertStatus(409);
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $this->assertSame(2, $service->forRoster($roster, $fixture['faculty'])['count']);
        $course->update(['is_current' => false, 'status' => CourseEnrollment::StatusDropped]);
        $stale = $service->preview($roster, $fixture['faculty']);
        $this->assertSame([], $stale['rows']);
        $this->assertSame('The class roster changed. Refresh the roster before generating an output.', $stale['error']);
        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.csv', $roster))->assertStatus(409);
        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.print', $roster))->assertStatus(409);
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $preview = $service->forRoster($roster, $fixture['faculty']);
        $this->assertSame(1, $preview['count']);
        $this->assertSame(2, $roster->rows()->count());
        $this->assertNotContains('LEGACY-ONLY', array_column($preview['rows'], 5));
        $this->assertSame(0, OutputAccessLog::query()->where('source_record_type', GradeRoster::class)->where('source_record_id', $roster->id)->count());
    }

    #[Test]
    public function class_roster_modal_is_shared_by_assigned_faculty_and_registrar_and_refresh_keeps_the_grade_draft(): void
    {
        $fixture = $this->fixture();
        $fixture['student']->update(['first_name' => 'Mira', 'middle_name' => 'A', 'last_name' => 'Santos', 'student_number' => 'MODAL-ROSTER-001']);
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-MODAL-MAIN');
        app(ManageTeachingAssignment::class)->addCoFaculty($fixture['section'], $coFaculty, $fixture['registrar'], 'ASSIGN-MODAL-CO');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach ([$fixture['faculty'], $coFaculty] as $faculty) {
            Livewire::actingAs($faculty)->test(FacultyGradeRoster::class)->mountAction('downloadRoster')
                ->assertMountedActionModalSee('MODAL-ROSTER-001')->assertMountedActionModalSee('Santos, Mira A')
                ->assertMountedActionModalSee('8 columns')->assertMountedActionModalSee('Current membership is checked again on download.');
        }
        Livewire::actingAs($fixture['registrar'])->test(ViewGradeRoster::class, ['record' => $roster->id])
            ->mountAction('exportRoster')->assertMountedActionModalSee('MODAL-ROSTER-001')
            ->assertMountedActionModalSee('Santos, Mira A');
        Livewire::actingAs($this->staff(User::StaffRoleAcademicHead))->test(ViewGradeRoster::class, ['record' => $roster->id])
            ->assertActionHidden('exportRoster');

        $page = Livewire::actingAs($fixture['faculty'])->test(FacultyGradeRoster::class);
        $rowKey = array_key_first($page->get('data.rows'));
        $page->set('data.rows.'.$rowKey.'.final_result', '2.00')->mountAction('downloadRoster');
        $fixture['course_enrollment']->update(['is_current' => false]);
        $page->callMountedAction()->assertMountedActionModalSee('No current learners')
            ->assertSet('data.rows.'.$rowKey.'.final_result', '2.00');
        $fixture['course_enrollment']->update(['is_current' => true]);
        $page->callMountedAction(['refresh' => true])->assertMountedActionModalSee('MODAL-ROSTER-001')
            ->assertSet('data.rows.'.$rowKey.'.final_result', '2.00');
        $this->assertNull($roster->rows()->sole()->final_result);
        $this->assertSame(0, OutputAccessLog::query()->where('source_record_type', GradeRoster::class)->where('source_record_id', $roster->id)->count());
    }

    #[Test]
    public function authorized_membership_snapshots_allow_head_reading_without_granting_output_access(): void
    {
        $fixture = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-SNAPSHOT');
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        app(ManageTeachingAssignment::class)->addCoFaculty($fixture['section'], $coFaculty, $fixture['registrar'], 'CO-SNAPSHOT');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $head = $this->staff(User::StaffRoleAcademicHead);
        $service = app(CurrentClassRosterOutput::class);

        foreach ([$fixture['faculty'], $coFaculty, $fixture['registrar'], $head] as $actor) {
            $snapshot = $service->membershipSnapshot($roster, $actor);
            $this->assertTrue($snapshot['isCurrent']);
            $this->assertNull($snapshot['error']);
            $this->assertSame(1, $snapshot['count']);
            $this->assertSame([$fixture['course_enrollment']->id], $snapshot['roster']->rows->pluck('course_enrollment_id')->all());
        }
        $this->actingAs($head)->get(route('grade-rosters.csv', $roster))->assertForbidden();
        $this->actingAs($head)->get(route('grade-rosters.print', $roster))->assertForbidden();
        $head->assignRole(User::StaffRoleFaculty);
        $this->actingAs($head)->get(route('grade-rosters.csv', $roster))->assertForbidden();
        foreach ([$this->staff(User::StaffRoleFaculty), $fixture['student']->user] as $actor) {
            try {
                $service->membershipSnapshot($roster, $actor);
                $this->fail('An unassigned actor read the membership snapshot.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame(0, OutputAccessLog::query()->where('source_record_type', GradeRoster::class)->where('source_record_id', $roster->id)->count());
    }

    #[Test]
    public function membership_snapshots_hide_stale_rows_and_distinguish_a_current_empty_class(): void
    {
        $fixture = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-SNAPSHOT-STATE');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $service = app(CurrentClassRosterOutput::class);
        $fixture['course_enrollment']->update(['is_current' => false]);

        $stale = $service->membershipSnapshot($roster, $fixture['registrar']);

        $this->assertFalse($stale['isCurrent']);
        $this->assertSame(0, $stale['count']);
        $this->assertCount(0, $stale['roster']->rows);
        $this->assertSame('The class roster changed. Refresh the roster before generating an output.', $stale['error']);
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $empty = $service->membershipSnapshot($roster, $fixture['faculty']);
        $this->assertTrue($empty['isCurrent']);
        $this->assertNull($empty['error']);
        $this->assertSame(0, $empty['count']);
        $this->assertCount(0, $empty['roster']->rows);
        $this->assertSame(1, $roster->rows()->count());
        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.csv', $roster))->assertStatus(409);
    }

    #[Test]
    public function faculty_result_entry_saves_native_result_fields_without_retyping_student_identity(): void
    {
        $fixture = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-ENTRY');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)->assertSet('rosterEditable', true);
        $rowKey = array_key_first($page->get('data.rows'));
        $page->set('data.rows.'.$rowKey.'.final_result', '2.00')->call('saveDraft')->assertHasNoErrors()->assertNotified('Roster draft saved');
        $this->assertSame('2.00', $roster->rows()->sole()->final_result);
        $rowKey = array_key_first($page->get('data.rows'));
        $page->set('data.rows.'.$rowKey.'.final_result', 'INC')->set('data.rows.'.$rowKey.'.inc_completion_note', 'Complete the remaining project')
            ->call('saveDraft')->assertHasNoErrors()->assertNotified('Roster draft saved');
        $this->assertSame('INC', $roster->rows()->sole()->final_result);
        $this->assertSame('Complete the remaining project', $roster->rows()->sole()->inc_completion_note);
    }

    #[Test]
    public function faculty_class_worklist_filters_owned_classes_and_keeps_stale_membership_unavailable(): void
    {
        $fixture = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-WORKLIST');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::withQueryParams(['classes' => 1])->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertSet('rosterId', null)->assertSee('Choose a class')->assertSee($fixture['section']->code)
            ->assertSee(FacultyGradeRoster::getUrl(['roster' => $roster->id]), escape: false)
            ->assertActionHidden('submit')->assertDontSee('Save draft');
        Livewire::withQueryParams(['classes' => 1, 'q' => 'nonexistent-class-search'])->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertDontSee($fixture['section']->code)->assertSee('No assigned roster');
        $fixture['course_enrollment']->update(['is_current' => false]);
        Livewire::withQueryParams(['classes' => 1])->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertSee('Membership needs an update')->assertDontSee($fixture['student']->student_number);
        Livewire::withQueryParams([]);
    }

    #[Test]
    public function faculty_page_hides_stale_membership_and_refuses_to_save_a_previously_open_grade_draft(): void
    {
        $fixture = $this->fixture();
        $fixture['student']->update(['student_number' => 'STALE-FACULTY-001']);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-STALE-PAGE');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['faculty'])->test(FacultyGradeRoster::class);
        $rowKey = array_key_first($page->get('data.rows'));
        $page->set('data.rows.'.$rowKey.'.final_result', '2.00');
        $fixture['course_enrollment']->update(['is_current' => false]);

        $page->call('saveDraft')->assertHasErrors(['rows'])->assertNotified('Class membership changed')
            ->assertSee('Class membership needs an update')->assertDontSee('STALE-FACULTY-001')
            ->assertActionHidden('printRoster')->assertActionHidden('downloadRoster');

        $this->assertFalse($page->instance()->canEditSelectedRoster());
        $this->assertNull($roster->rows()->sole()->final_result);
        $this->assertSame(0, $roster->versions()->count());
    }

    #[Test]
    public function cofaculty_page_shows_current_legal_identity_and_results_as_read_only_membership(): void
    {
        $fixture = $this->fixture();
        $fixture['student']->update(['student_number' => 'CO-FACULTY-001', 'first_name' => 'Mira', 'middle_name' => 'A', 'last_name' => 'Santos']);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-READ-PAGE');
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        app(ManageTeachingAssignment::class)->addCoFaculty($fixture['section'], $coFaculty, $fixture['registrar'], 'ASSIGN-CO-READ-PAGE');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $roster->rows()->sole()->update(['final_result' => '2.00']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($coFaculty)->test(FacultyGradeRoster::class)
            ->assertSee('View-only co-faculty')->assertSee('CO-FACULTY-001')->assertSee('Santos, Mira A')
            ->assertSee('2.00')->assertDontSee('Save draft')->assertActionHidden('submit');

        $this->assertFalse($page->instance()->canEditSelectedRoster());
        $page->assertSee('1 enrolled student');
    }

    #[Test]
    public function head_membership_reading_hides_draft_results_and_stale_or_historical_members(): void
    {
        $fixture = $this->fixture();
        $fixture['student']->update(['first_name' => 'Mira', 'middle_name' => 'A', 'last_name' => 'Santos', 'student_number' => 'HEAD-MEMBER-001']);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'HEAD-MEMBERSHIP-001');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $roster->rows()->update(['final_result' => '1.25', 'inc_completion_note' => 'PRIVATE-DRAFT-NOTE']);
        $head = $this->staff(User::StaffRoleAcademicHead);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($head)->test(ViewGradeRoster::class, ['record' => $roster->id])
            ->assertSee('1 enrolled student')->assertSee('HEAD-MEMBER-001')->assertSee('Santos, Mira A')
            ->assertDontSee('1.25')->assertDontSee('PRIVATE-DRAFT-NOTE')
            ->assertActionHidden('exportRoster')->assertActionHidden('return')->assertActionHidden('postAndRelease');

        $other = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($other['section'], $other['faculty'], $other['registrar'], 'HEAD-MEMBERSHIP-OTHER');
        $otherRoster = app(SynchronizeOfficialGradeRoster::class)->execute($other['section'], $other['registrar']);
        Livewire::actingAs($head)->test(ListGradeRosters::class)
            ->set('oversightTermId', $other['term']->id)
            ->assertCanSeeTableRecords([$roster, $otherRoster])
            ->filterTable('term', $fixture['term']->id)
            ->assertCanSeeTableRecords([$roster])->assertCanNotSeeTableRecords([$otherRoster])
            ->searchTable('NO-SUCH-CLASS')->assertCanNotSeeTableRecords([$roster]);

        $fixture['course_enrollment']->update(['is_current' => false, 'status' => CourseEnrollment::StatusDropped]);

        Livewire::actingAs($head)->test(ViewGradeRoster::class, ['record' => $roster->id])
            ->assertSee('Class membership needs an update')->assertDontSee('HEAD-MEMBER-001');

        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        Livewire::actingAs($head)->test(ViewGradeRoster::class, ['record' => $roster->id])
            ->assertSee('0 enrolled students')->assertSee('No officially enrolled students are currently assigned to this class.')
            ->assertDontSee('HEAD-MEMBER-001');
        $page = Livewire::actingAs($head)->test(ListGradeRosters::class)->assertCanSeeTableRecords([$roster]);
        $this->assertSame(0, $page->instance()->getTableRecords()->firstWhere('id', $roster->id)->current_members_count);
        Livewire::actingAs($fixture['registrar'])->test(ListGradeRosters::class)
            ->assertTableColumnStateSet('rows_count', 0, $roster);
        $this->assertSame(1, $roster->rows()->count());
        $this->assertSame('1.25', $roster->rows()->sole()->final_result);
    }

    /** @return array{registrar: User, faculty: User, student: StudentProfile, term: Term, section: Section, course_enrollment: CourseEnrollment} */
    private function fixture(
        ?Term $term = null,
        ?StudentProfile $student = null,
        string $termEndsOn = '2027-05-31',
        string $classification = CourseSpecification::AcademicClassificationOrdinary,
        ?CourseSpecification $specification = null,
    ): array {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $term ??= Term::factory()->create([
            'starts_on' => Carbon::parse($termEndsOn)->subMonths(4),
            'ends_on' => $termEndsOn,
            'state' => Term::StateActive,
        ]);
        $student ??= StudentProfile::factory()->create();
        $specification ??= CourseSpecification::factory()->create([
            'academic_classification' => $classification,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $entry = CurriculumEntry::query()
            ->where('curriculum_version_id', $student->curriculum_version_id)
            ->where('course_specification_id', $specification->id)
            ->first()
            ?? CurriculumEntry::factory()->create([
                'curriculum_version_id' => $student->curriculum_version_id,
                'course_specification_id' => $specification->id,
            ]);
        $offering = TermOffering::factory()->create([
            'term_id' => $term->id,
            'curriculum_entry_id' => $entry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->create(['term_offering_id' => $offering->id, 'state' => Section::StateOpen]);
        $enrollment = Enrollment::query()->firstOrCreate(
            ['student_profile_id' => $student->id, 'term_id' => $term->id],
            [
                'credential_user_id' => $student->user_id,
                'case_reference' => 'REG-'.str()->upper(str()->random(12)),
                'selection_basis' => Enrollment::SelectionStandardCurriculum,
                'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
                'status' => 'officially_enrolled',
                'officially_enrolled_at' => now(),
            ],
        );
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => $specification->credit_units,
            'added_at' => now(),
        ]);
        $package = TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->first();
        if ($package === null) {
            $package = TermCalendarPackage::factory()->for($term)->create([
                'version' => (int) TermCalendarPackage::query()->where('term_id', $term->id)->max('version') + 1,
                'state' => TermCalendarPackage::StateActive,
                'activated_at' => now(),
            ]);
        }
        $package->windows()->firstOrCreate([
            'window_type' => TermCalendarWindow::TypeGradeEntry,
        ], [
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDay()->toDateString(),
            'cutoff_at' => '23:59:59',
        ]);

        return compact('registrar', 'faculty', 'student', 'term', 'section') + ['course_enrollment' => $courseEnrollment];
    }

    /** @param array{registrar: User, faculty: User, student: StudentProfile, term: Term, section: Section, course_enrollment: CourseEnrollment} $fixture */
    private function releaseResult(array $fixture, string $result, ?string $incNote = null): GradeOutcomeEvent
    {
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-RELEASE');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        app(SaveFinalGradeResult::class)->execute($roster->rows->sole(), $result, $incNote, $fixture['faculty']);
        $submitted = app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);

        return app(PostAndReleaseGradeRoster::class)
            ->execute($submitted, $fixture['registrar'], 'REG-G02-RELEASE')
            ->rows->sole()->outcomeEvents->sortByDesc('id')->first();
    }

    /** @param array{registrar: User, faculty: User, student: StudentProfile, term: Term, section: Section, course_enrollment: CourseEnrollment} $fixture */
    private function addStudentToOffering(array $fixture): void
    {
        $student = StudentProfile::factory()->create([
            'program_id' => $fixture['student']->program_id,
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'credential_user_id' => $student->user_id,
            'term_id' => $fixture['term']->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $fixture['section']->term_offering_id,
            'section_id' => $fixture['section']->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);
    }

    /** @param array{registrar: User, faculty: User, student: StudentProfile, term: Term, section: Section, course_enrollment: CourseEnrollment} $fixture */
    private function pendingRegistrationCaseFor(array $fixture): Enrollment
    {
        $sourceCourse = $fixture['course_enrollment']->termOffering->curriculumEntry->courseSpecification->course;
        $dependentTerm = Term::factory()->create([
            'starts_on' => $fixture['term']->starts_on->addMonths(5),
            'ends_on' => $fixture['term']->ends_on->addMonths(5),
        ]);
        $dependentSpecification = CourseSpecification::factory()->create([
            'state' => CourseSpecification::StateActive,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
        ]);
        CourseRequirement::factory()->create([
            'course_specification_id' => $dependentSpecification->id,
            'related_course_id' => $sourceCourse->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'state' => CourseRequirement::StateActive,
        ]);
        $dependentEntry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $fixture['student']->curriculum_version_id,
            'course_specification_id' => $dependentSpecification->id,
        ]);
        $dependentOffering = TermOffering::factory()->create([
            'term_id' => $dependentTerm->id,
            'curriculum_entry_id' => $dependentEntry->id,
            'state' => TermOffering::StateScheduled,
        ]);
        $nextCase = Enrollment::factory()->create([
            'credential_user_id' => $fixture['student']->user_id,
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $dependentTerm->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'status' => 'pending',
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $nextCase->id,
            'term_offering_id' => $dependentOffering->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => 3,
            'added_at' => now(),
        ]);

        return $nextCase;
    }

    private function assertImpactReviewCount(Enrollment $registrationCase, int $expected): void
    {
        $this->assertSame($expected, RegistrationCaseEvent::query()
            ->where('enrollment_id', $registrationCase->id)
            ->where('event_type', 'AcademicResultImpactReviewOpened')
            ->count());
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
