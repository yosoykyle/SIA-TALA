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
use App\Filament\Student\Pages\Academics as StudentAcademics;
use App\Mail\AcademicRecordChangedMail;
use App\Models\AcademicDecision;
use App\Models\CalendarEvent;
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
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OfficialRosterToStudentAcademicsJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

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
        $package = TermCalendarPackage::factory()->create([
            'term_id' => $fixture['term']->id,
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
            ->assertSee('Examination Period')
            ->assertSee('May 10, 2027')
            ->assertSee('May 15, 2027')
            ->assertSee('BOARD-CALENDAR-2026-03')
            ->assertSee('Class-level examination arrangements are not inferred.');
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
        $this->assertStringContainsString('Term,"Class Reference","Course Code","Course Title","Program or Cohort","Student Number","Legal Name","Official Enrollment State","As of (Asia/Manila)"', $csv->streamedContent());
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} PHT/', $csv->streamedContent());
        $this->assertStringContainsString("'=HYPERLINK", $csv->streamedContent());
        $this->assertStringContainsString("\r\n", $csv->streamedContent());

        $unrelatedFaculty = $this->staff(User::StaffRoleFaculty);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $this->actingAs($unrelatedFaculty)->get(route('grade-rosters.print', $roster))->assertForbidden();
        $this->actingAs($academicHead)->get(route('grade-rosters.print', $roster))->assertForbidden();
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
            ->assertSee('Curriculum and enrollment guidance');

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
        $fixture = $this->fixture();
        $emptyOffering = TermOffering::factory()->create(['term_id' => $fixture['term']->id]);
        $emptySection = Section::factory()->create(['term_offering_id' => $emptyOffering->id, 'state' => Section::StateOpen]);
        app(ManageTeachingAssignment::class)->designate($emptySection, $fixture['faculty'], $fixture['registrar'], 'ASSIGN-EMPTY-001');
        $emptyRoster = app(SynchronizeOfficialGradeRoster::class)->execute($emptySection, $fixture['registrar']);

        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.print', $emptyRoster))->assertStatus(409);
        $this->actingAs($fixture['faculty'])->get(route('grade-rosters.csv', $emptyRoster))->assertStatus(409);

        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-MAIN-001');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $otherStudent = StudentProfile::factory()->create();
        $otherEnrollment = Enrollment::factory()->create(['student_profile_id' => $otherStudent->id, 'term_id' => $fixture['term']->id, 'status' => 'officially_enrolled']);
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
        CalendarEvent::factory()->create([
            'term_id' => $term->id,
            'process_key' => 'grade_entry',
            'state' => CalendarEvent::StateActive,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
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
