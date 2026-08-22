<?php

namespace Tests\Feature\Academics;

use App\Actions\Academics\AcademicAverageReadiness;
use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\RecordAcademicDecision;
use App\Actions\Academics\RecordExternalCompetencyResult;
use App\Actions\Academics\TermWeightedAverageProjection;
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
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleFaculty] as $role) {
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

        Mail::fake();
        $overdueFixture = $this->fixture(termEndsOn: today()->subYears(2)->toDateString());
        $overdue = $this->releaseResult($overdueFixture, 'INC', 'Complete approved outstanding work.');
        Mail::assertQueuedCount(1);
        $this->assertSame(IncDeadlineService::StateCompletionOverdue, app(IncDeadlineService::class)->state($overdue));
        app(AmendIncDeadline::class)->execute(
            $overdue, today()->addMonth(), 'INC-EXTENSION-001', today(),
            'Approved extension based on documented circumstances.', $overdueFixture['registrar'],
        );
        $this->assertSame(IncDeadlineService::StateCompletionOpen, app(IncDeadlineService::class)->state($overdue));
        $this->assertSame('INC', $overdue->row->fresh()->current_outcome_code);
        Mail::assertQueuedCount(1);
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
        $first = app(RecordExternalCompetencyResult::class)->execute(
            $requirement, $fixture['student'], ExternalCompetencyResult::OutcomeNotYetCompetent,
            'ASSESSOR-001', 'REG-G06-001', today(), $fixture['registrar'],
        );
        $second = app(RecordExternalCompetencyResult::class)->execute(
            $requirement, $fixture['student'], ExternalCompetencyResult::OutcomeCompetent,
            'ASSESSOR-002', 'REG-G06-002', today(), $fixture['registrar'],
        );
        $this->assertFalse($first->fresh()->is_current);
        $this->assertSame($first->id, $second->supersedes_result_id);
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
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->streamedContent());
        $this->assertStringContainsString("'=HYPERLINK", $csv->streamedContent());
        $this->assertStringContainsString("\r\n", $csv->streamedContent());

        $unrelatedFaculty = $this->staff(User::StaffRoleFaculty);
        $this->actingAs($unrelatedFaculty)->get(route('grade-rosters.print', $roster))->assertForbidden();
        $this->assertSame(2, OutputAccessLog::query()->where('source_record_type', GradeRoster::class)->where('source_record_id', $roster->id)->count());

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

        $fixture['student']->user?->update(['email' => 'invalid-recipient']);
        $notification->refresh()->update(['status' => OperationalEvent::StatusFailed]);
        app(AcademicRecordNotificationService::class)->resend($notification->fresh(), $fixture['student']->user->fresh());

        $this->assertSame(OperationalEvent::StatusFailed, $notification->fresh()->status);
        $this->assertSame('2.75', $event->row->fresh()->current_outcome_code);
        $this->assertSame(GradeRoster::StateReleased, $event->row->roster->fresh()->state);
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
