<?php

namespace Tests\Feature\Academics;

use App\Actions\Academics\AcademicAverageReadiness;
use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\ExaminationPeriodProjection;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Academics\RecordAcademicDecision;
use App\Actions\Academics\RecordExternalCompetencyResult;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Grades\AmendIncDeadline;
use App\Actions\Grades\CurrentClassRosterOutput;
use App\Actions\Grades\FinalResultPolicy;
use App\Actions\Grades\IncDeadlineService;
use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\OpenRegistrationImpactReviewsForGradeOutcome;
use App\Actions\Grades\PostAndReleaseGradeRoster;
use App\Actions\Grades\RecordApprovedGradeCorrection;
use App\Actions\Grades\ReleaseIncCompletion;
use App\Actions\Grades\ReturnGradeRoster;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SubmitIncCompletion;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CompletionAndTor;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Filament\Student\Pages\Academics as StudentAcademics;
use App\Mail\AcademicRecordChangedMail;
use App\Models\AcademicDecision;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseDropRecord;
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
use App\Models\TermAverageLabel;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\InteractsWithGradeRosterDrafts;
use Tests\TestCase;

class OfficialRosterToStudentAcademicsJourneyTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithGradeRosterDrafts;

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

        $this->saveGradeRosterDraft($roster, $fixture['faculty'], [
            $roster->rows->sole()->id => ['final_result' => 'INC', 'inc_completion_note' => 'Complete the remaining practical evidence.'],
        ]);
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
        $this->saveGradeRosterDraft($row->roster, $fixture['faculty'], [
            $row->id => ['final_result' => '2.00', 'inc_completion_note' => null],
        ]);
        $submitted = app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
        app(ReturnGradeRoster::class)->execute($submitted, $fixture['registrar'], 'Recheck the source result.', [$row->id]);

        $this->assertNotNull($row->fresh()->returned_at);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $facultyPage = Livewire::withQueryParams(['roster' => $submitted->id])->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertSee('Changes requested')
            ->assertSee('Recheck the source result.')
            ->assertSee('Changes requested by Registrar');
        $this->assertSame([true], array_column($facultyPage->get('data.rows'), 'returned_for_review'));
        $this->assertSame(GradeRosterVersion::StateReturned, $submitted->versions()->sole()->state);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeGradeRosterReturnedEmail)->count());
        $this->saveGradeRosterDraft($row->roster, $fixture['faculty'], [
            $row->id => ['final_result' => '2.25', 'inc_completion_note' => null],
        ]);
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

        $this->saveGradeRosterDraft($roster, $fixture['faculty'], [
            $roster->rows->sole()->id => ['final_result' => '2.00', 'inc_completion_note' => null],
        ]);
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
    #[DataProvider('inactiveRosterSources')]
    public function unsynchronized_designated_assignment_changes_cannot_submit_or_release(string $operation, string $change): void
    {
        $fixture = $this->fixture();
        $assignment = app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'AC2-ACTIVE-ASSIGNMENT');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $this->saveGradeRosterDraft($roster, $fixture['faculty'], [
            $roster->rows->sole()->id => ['final_result' => '2.00', 'inc_completion_note' => null],
        ]);
        if ($operation === 'release') {
            $roster = app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
        }
        $assignment->update($change === 'inactive'
            ? ['state' => ClassOfferingTeachingAssignment::StateReplaced, 'ended_at' => now()]
            : ['role' => ClassOfferingTeachingAssignment::RoleCoFaculty]);
        $before = $roster->only(['state', 'current_version_number', 'membership_signature']);
        $notificationCount = OperationalEvent::query()->count();

        try {
            if ($operation === 'submit') {
                app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
            } else {
                app(PostAndReleaseGradeRoster::class)->execute($roster, $fixture['registrar']);
            }
            $this->fail('An unchanged assignment id must not conceal a stale designated Faculty source.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The designated Faculty assignment changed. Refresh the roster before continuing.', $exception->getMessage());
        }

        $this->assertSame($before, $roster->fresh()->only(array_keys($before)));
        $this->assertSame($operation === 'release' ? 1 : 0, $roster->versions()->count());
        $this->assertSame(0, GradeOutcomeEvent::query()->count());
        $this->assertSame($notificationCount, OperationalEvent::query()->count());
    }

    /** @return array<string, array{string, string}> */
    public static function inactiveRosterSources(): array
    {
        return [
            'inactive before submission' => ['submit', 'inactive'],
            'co-Faculty before submission' => ['submit', 'role'],
            'inactive before release' => ['release', 'inactive'],
            'co-Faculty before release' => ['release', 'role'],
        ];
    }

    #[Test]
    #[DataProvider('unsynchronizedMembershipChanges')]
    public function unsynchronized_canonical_membership_changes_cannot_submit_or_release(string $operation, string $change): void
    {
        $fixture = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'AC2-MEMBERSHIP');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $this->saveGradeRosterDraft($roster, $fixture['faculty'], [
            $roster->rows->sole()->id => ['final_result' => '2.00', 'inc_completion_note' => null],
        ]);
        if ($operation === 'release') {
            $roster = app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
        }
        if ($change === 'addition') {
            $this->addStudentToOffering($fixture);
        } elseif ($change === 'removed') {
            $fixture['course_enrollment']->update(['is_current' => false]);
        } else {
            $fixture['course_enrollment']->enrollment->update(['canonical_outcome' => Enrollment::OutcomeCancelledByRegistrar]);
        }
        $before = $roster->only(['state', 'current_version_number', 'membership_signature']);
        $notificationCount = OperationalEvent::query()->count();

        try {
            if ($operation === 'submit') {
                app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
            } else {
                app(PostAndReleaseGradeRoster::class)->execute($roster, $fixture['registrar']);
            }
            $this->fail('Stored roster membership must not permit a stale or partial submission/release.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Official class membership changed. Refresh and resubmit the complete roster before continuing.', $exception->getMessage());
        }

        $this->assertSame($before, $roster->fresh()->only(array_keys($before)));
        $this->assertSame($operation === 'release' ? 1 : 0, $roster->versions()->count());
        $this->assertSame(0, GradeOutcomeEvent::query()->count());
        $this->assertSame($notificationCount, OperationalEvent::query()->count());
    }

    /** @return array<string, array{string, string}> */
    public static function unsynchronizedMembershipChanges(): array
    {
        return [
            'addition before submission' => ['submit', 'addition'],
            'removal before submission' => ['submit', 'removed'],
            'nonofficial case before submission' => ['submit', 'nonofficial'],
            'addition before release' => ['release', 'addition'],
            'removal before release' => ['release', 'removed'],
            'nonofficial case before release' => ['release', 'nonofficial'],
        ];
    }

    #[Test]
    public function previously_reviewed_version_cannot_submit_return_or_release_a_newer_submission(): void
    {
        $fixture = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'AC2-REVIEWED-VERSION');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $row = $roster->rows->sole();
        $this->saveGradeRosterDraft($row->roster, $fixture['faculty'], [
            $row->id => ['final_result' => '2.00', 'inc_completion_note' => null],
        ]);
        $submitted = app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty'], expectedVersion: 0);
        $reviewedVersion = $submitted->current_version_number;
        app(ReturnGradeRoster::class)->execute($submitted, $fixture['registrar'], 'Recheck the original source.', [$row->id], expectedVersion: $reviewedVersion);
        $this->saveGradeRosterDraft($row->roster, $fixture['faculty'], [
            $row->id => ['final_result' => '2.25', 'inc_completion_note' => null],
        ]);
        $resubmitted = app(SubmitGradeRoster::class)->execute($submitted, $fixture['faculty'], expectedVersion: $reviewedVersion);
        $notificationCount = OperationalEvent::query()->count();

        foreach (['submit', 'return', 'release'] as $operation) {
            try {
                match ($operation) {
                    'submit' => app(SubmitGradeRoster::class)->execute($submitted, $fixture['faculty'], expectedVersion: 0),
                    'return' => app(ReturnGradeRoster::class)->execute($submitted, $fixture['registrar'], 'Do not return unreviewed results.', [$row->id], expectedVersion: $reviewedVersion),
                    'release' => app(PostAndReleaseGradeRoster::class)->execute($submitted, $fixture['registrar'], expectedVersion: $reviewedVersion),
                };
                $this->fail('A prior reviewed version must not act on the latest submission.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The roster version changed. Reload and review the current submission before continuing.', $exception->getMessage());
            }
        }

        $this->assertSame(GradeRoster::StateSubmitted, $resubmitted->fresh()->state);
        $this->assertSame(2, $resubmitted->versions()->count());
        $this->assertSame(0, GradeOutcomeEvent::query()->count());
        $this->assertSame($notificationCount, OperationalEvent::query()->count());
        $released = app(PostAndReleaseGradeRoster::class)->execute($resubmitted, $fixture['registrar'], expectedVersion: 2);
        app(PostAndReleaseGradeRoster::class)->execute($released, $fixture['registrar'], expectedVersion: 2);

        $this->assertSame('2.25', $row->fresh()->current_outcome_code);
        $this->assertSame(1, $row->outcomeEvents()->count());
        $this->assertSame(GradeRoster::StateReleased, $released->state);
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
        $successor = app(ReleaseIncCompletion::class)->execute($submission, $fixture['registrar']);
        $this->assertNull($successor->authority);
        app(ReleaseIncCompletion::class)->execute($submission->fresh(), $fixture['registrar']);

        $this->assertSame($event->id, $successor->predecessor_event_id);
        $this->assertSame('INC', $event->fresh()->result_code);
        $this->assertSame(IncDeadlineService::StateResolved, app(IncDeadlineService::class)->state($event));
        $this->assertImpactReviewCount($nextCase, 2);

        $corrected = app(RecordApprovedGradeCorrection::class)->execute(
            $event->row, '2.25', 'Approved correction.', $fixture['registrar'],
        );
        $correction = $corrected->outcomeEvents()->latest('id')->firstOrFail();
        $this->assertNull($correction->authority);
        $this->assertNull($correction->evidence_reference);
        app(RecordApprovedGradeCorrection::class)->execute(
            $event->row, '2.25', 'Approved correction.', $fixture['registrar'],
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
        Mail::assertQueued(AcademicRecordChangedMail::class, fn (AcademicRecordChangedMail $mail): bool => $mail->operationalEventType === OperationalEvent::TypeCompletionRequiresActionEmail
            && $mail->hasTo($overdueFixture['student']->user->email));
        Mail::assertQueuedCount(5);
        $this->assertSame(IncDeadlineService::StateCompletionOverdue, app(IncDeadlineService::class)->state($overdue));
        app(AmendIncDeadline::class)->execute(
            $overdue, today()->addMonth(),
            'Approved extension based on documented circumstances.', $overdueFixture['registrar'],
        );
        $this->assertSame(IncDeadlineService::StateCompletionOpen, app(IncDeadlineService::class)->state($overdue));
        $this->assertSame('INC', $overdue->row->fresh()->current_outcome_code);
        Mail::assertQueuedCount(7);
        $this->assertSame(2, OperationalEvent::query()->where('event_type', OperationalEvent::TypeIncDeadlineAmendedEmail)->count());
    }

    #[Test]
    public function registrar_external_result_form_scopes_requirements_and_validates_optional_credentials(): void
    {
        $fixture = $this->fixture();
        $student = $fixture['student'];
        $student->curriculumVersion->update(['state' => 'ACTIVE']);
        $requirement = ExternalCompetencyRequirement::factory()->create([
            'curriculum_version_id' => $student->curriculum_version_id, 'state' => 'ACTIVE',
        ]);
        ExternalCompetencyRequirement::factory()->create([
            'curriculum_version_id' => $student->curriculum_version_id, 'state' => 'Draft',
        ]);
        $other = StudentProfile::factory()->create();
        $other->curriculumVersion->update(['state' => 'ACTIVE']);
        $otherRequirement = ExternalCompetencyRequirement::factory()->create([
            'curriculum_version_id' => $other->curriculum_version_id, 'state' => 'ACTIVE',
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($fixture['registrar'])->test(ViewStudentProfile::class, ['record' => $student->id])
            ->mountAction('recordExternalCompetency')
            ->assertFormFieldExists('requirement_id', fn (Select $field): bool => array_keys($field->getOptions()) === [$requirement->id])
            ->set('mountedActions.0.data.requirement_id', $requirement->id)
            ->set('mountedActions.0.data.student_profile_id', $other->id)
            ->assertActionDataSet(['requirement_id' => $requirement->id, 'expected_predecessor_id' => 0])
            ->assertFormFieldExists('requirement_id', fn (Select $field): bool => array_keys($field->getOptions()) === [$requirement->id])
            ->set('mountedActions.0.data.requirement_id', $requirement->id)
            ->setActionData([
                'outcome' => ExternalCompetencyResult::OutcomeCompetent,
                'assessment_date' => today()->toDateString(), 'external_source' => 'Verified external assessor',
                'evidence_reference' => 'PRIVATE-EXTERNAL-UI', 'authority_reference' => 'REG-EXTERNAL-UI',
                'authority_date' => today()->toDateString(), 'credential_type' => 'NC',
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['credential_reference' => 'required_with'])
            ->assertActionMounted('recordExternalCompetency');
        $this->assertDatabaseCount('external_competency_results', 0);

        $page->setActionData(['credential_reference' => 'NC-UI-001', 'credential_valid_until' => today()->addYear()->toDateString()])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('External competency result recorded');

        $result = ExternalCompetencyResult::query()->sole();
        $this->assertSame($student->id, $result->student_profile_id);
        $this->assertSame($requirement->id, $result->external_competency_requirement_id);
        $this->assertSame('NC-UI-001', $result->credential_reference);
        $this->assertSame($fixture['registrar']->id, $result->recorded_by);
    }

    #[Test]
    public function registrar_external_result_form_preserves_input_when_a_reassessment_becomes_stale(): void
    {
        $fixture = $this->fixture();
        $student = $fixture['student'];
        $student->curriculumVersion->update(['state' => 'ACTIVE']);
        $requirement = ExternalCompetencyRequirement::factory()->create([
            'curriculum_version_id' => $student->curriculum_version_id, 'state' => 'ACTIVE',
        ]);
        $first = app(RecordExternalCompetencyResult::class)->execute(
            $requirement, $student, ExternalCompetencyResult::OutcomeNotYetCompetent,
            'PRIVATE-ORIGINAL', 'REG-ORIGINAL', today(), $fixture['registrar'],
        );
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($fixture['registrar'])->test(ViewStudentProfile::class, ['record' => $student->id])
            ->mountAction('recordExternalCompetency')
            ->set('mountedActions.0.data.requirement_id', $requirement->id)
            ->assertActionDataSet(['expected_predecessor_id' => $first->id]);
        $successor = app(RecordExternalCompetencyResult::class)->execute(
            $requirement, $student, ExternalCompetencyResult::OutcomeCompetent,
            'PRIVATE-CONCURRENT', 'REG-CONCURRENT', today(), $fixture['registrar'], expectedPredecessorId: $first->id,
        );

        $page->setActionData([
            'outcome' => ExternalCompetencyResult::OutcomeCompetent,
            'assessment_date' => today()->toDateString(), 'external_source' => 'Verified external assessor',
            'evidence_reference' => 'PRIVATE-STALE', 'authority_reference' => 'REG-STALE',
            'authority_date' => today()->toDateString(),
        ])->callMountedAction()->assertHasNoActionErrors()
            ->assertActionMounted('recordExternalCompetency')->assertNotified('Result was not recorded')
            ->assertActionDataSet(['evidence_reference' => 'PRIVATE-STALE', 'authority_reference' => 'REG-STALE']);

        $this->assertDatabaseCount('external_competency_results', 2);
        $this->assertTrue($successor->fresh()->is_current);
        $this->assertFalse($first->fresh()->is_current);
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
        $zeroUnits = $this->fixture(term: $fixture['term'], student: $fixture['student']);
        $zeroUnits['course_enrollment']->update(['units_snapshot' => '0.00']);
        $this->releaseResult($zeroUnits, '5.00');

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
    public function cumulative_average_stops_before_the_first_incomplete_term_and_retains_its_through_context(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $first = $this->fixture(termEndsOn: '2025-05-31');
        $this->releaseResult($first, '5.00');
        TermAverageLabel::query()->create([
            'term_id' => $first['term']->id, 'label' => 'Legacy GPA',
            'authority_reference' => 'HISTORICAL-LABEL', 'authority_date' => today(), 'recorded_by' => $first['registrar']->id,
            'recorded_at' => now(), 'is_current' => true,
        ]);
        $second = $this->fixture(student: $first['student'], termEndsOn: '2026-05-31');
        $this->releaseResult($second, '1.00');
        $missing = $this->fixture(term: $second['term'], student: $first['student']);
        $third = $this->fixture(student: $first['student'], termEndsOn: '2027-05-31');
        $this->releaseResult($third, '1.25');

        $partial = app(CumulativeGwaProjection::class)->forStudent($first['student']);

        $this->assertSame(AcademicAverageReadiness::GradesNotComplete, $partial['state']);
        $this->assertSame('5.00', $partial['value']);
        $this->assertSame($first['term']->label, $partial['through']);
        $this->assertSame(1, $partial['included_attempts']);
        $this->assertSame('Cumulative GWA', $partial['label']);
        $summary = view('components.academic-average', ['average' => $partial, 'attributes' => new ComponentAttributeBag])->render();
        $this->assertStringContainsString('5.00', $summary);
        $this->assertStringContainsString('Through '.$first['term']->label, $summary);
        $this->assertStringContainsString($partial['reason'], $summary);
        $this->assertStringNotContainsString('GradesNotComplete', $summary);
        $this->assertSame('Term weighted average', app(TermWeightedAverageProjection::class)->forStudentAndTerm($first['student'], $first['term'])['label']);
        $this->assertSame(1, TermAverageLabel::query()->where('term_id', $first['term']->id)->count());

        $this->releaseResult($missing, '4.00');
        $complete = app(CumulativeGwaProjection::class)->forStudent($first['student']);
        $historical = app(CumulativeGwaProjection::class)->forStudent($first['student'], $second['term']);

        $this->assertSame(AcademicAverageReadiness::Available, $complete['state']);
        $this->assertSame('2.81', $complete['value']);
        $this->assertSame($third['term']->label, $complete['through']);
        $this->assertSame(4, $complete['included_attempts']);
        $this->assertSame('3.33', $historical['value']);
        $this->assertSame($second['term']->label, $historical['through']);
        $this->assertSame(3, $historical['included_attempts']);
    }

    #[Test]
    public function overdue_inc_is_excluded_and_a_future_deadline_extension_restores_pending_readiness(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $inc = $this->fixture(termEndsOn: '2024-05-31');
        $event = $this->releaseResult($inc, 'INC', 'Complete the outstanding authorized work.');
        $numeric = $this->fixture(term: $inc['term'], student: $inc['student']);
        $this->releaseResult($numeric, '2.25');

        $settled = app(TermWeightedAverageProjection::class)->forStudentAndTerm($inc['student'], $inc['term']);

        $this->assertSame(AcademicAverageReadiness::Available, $settled['state']);
        $this->assertSame('2.25', $settled['value']);
        $this->assertStringContainsString('completion overdue, no credit', implode(' ', $settled['exclusions']));
        $this->assertSame('INC', $event->fresh()->result_code);

        app(AmendIncDeadline::class)->execute(
            $event, today()->addMonth(),
            'Approved extension restores the ordinary completion period.', $inc['registrar'],
        );
        $extended = app(TermWeightedAverageProjection::class)->forStudentAndTerm($inc['student'], $inc['term']);
        $cumulative = app(CumulativeGwaProjection::class)->forStudent($inc['student']);

        $this->assertSame(AcademicAverageReadiness::IncompleteResultPending, $extended['state']);
        $this->assertNull($extended['value']);
        $this->assertSame(AcademicAverageReadiness::IncompleteResultPending, $cumulative['state']);
        $this->assertNull($cumulative['value']);
        $this->assertNull($cumulative['through']);
    }

    #[Test]
    public function only_a_released_passing_attempt_settles_an_open_inc_across_course_revisions(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $inc = $this->fixture(termEndsOn: '2026-05-31');
        $event = $this->releaseResult($inc, 'INC', 'Complete the outstanding authorized work.');
        $numeric = $this->fixture(term: $inc['term'], student: $inc['student']);
        $this->releaseResult($numeric, '2.00');
        $originalSpecification = $inc['course_enrollment']->termOffering->curriculumEntry->courseSpecification;
        $newRevision = CourseSpecification::factory()->create([
            'course_id' => $originalSpecification->course_id,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
        ]);
        $retake = $this->fixture(student: $inc['student'], termEndsOn: '2027-05-31', specification: $newRevision);
        $failed = $this->releaseResult($retake, '5.00');

        $this->assertSame(AcademicAverageReadiness::IncompleteResultPending, app(TermWeightedAverageProjection::class)->forStudentAndTerm($inc['student'], $inc['term'])['state']);

        app(RecordApprovedGradeCorrection::class)->execute(
            $failed->row, '1.25',
            'Authorized correction confirms the separate passing attempt.', $retake['registrar'],
        );
        $original = app(TermWeightedAverageProjection::class)->forStudentAndTerm($inc['student'], $inc['term']);
        $current = app(CumulativeGwaProjection::class)->forStudent($inc['student']);

        $this->assertSame(AcademicAverageReadiness::Available, $original['state']);
        $this->assertSame('2.00', $original['value']);
        $this->assertSame('1.63', $current['value']);
        $this->assertSame(2, $current['included_attempts']);
        $this->assertSame('INC', $event->fresh()->result_code);
        $this->assertSame('5.00', $failed->fresh()->result_code);
        $this->assertStringContainsString('requirement satisfied', implode(' ', $original['exclusions']));
    }

    #[Test]
    public function applied_approved_credit_settles_inc_and_missing_outcomes_without_fabricating_zero(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $inc = $this->fixture(termEndsOn: '2026-05-31');
        $event = $this->releaseResult($inc, 'INC', 'Complete the outstanding authorized work.');
        $credited = $this->fixture(term: $inc['term'], student: $inc['student']);
        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $inc['student']->id,
            'term_id' => $inc['term']->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateRecordedApproved,
        ]);
        foreach ([$inc, $credited] as $fixture) {
            ProgramShiftCreditEntry::factory()->create([
                'student_lifecycle_change_id' => $change->id,
                'curriculum_entry_id' => $fixture['course_enrollment']->termOffering->curriculum_entry_id,
                'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
            ]);
        }

        $this->assertSame(AcademicAverageReadiness::GradesNotComplete, app(TermWeightedAverageProjection::class)->forStudentAndTerm($inc['student'], $inc['term'])['state']);

        $change->update(['state' => StudentLifecycleChange::StateApplied]);
        $average = app(TermWeightedAverageProjection::class)->forStudentAndTerm($inc['student'], $inc['term']);
        $cumulative = app(CumulativeGwaProjection::class)->forStudent($inc['student']);
        $results = app(OfficialCourseResultProjection::class)->forStudent($inc['student']);

        $this->assertSame(AcademicAverageReadiness::NotApplicable, $average['state']);
        $this->assertNull($average['value']);
        $this->assertSame(AcademicAverageReadiness::NotApplicable, $cumulative['state']);
        $this->assertNull($cumulative['value']);
        $this->assertSame(0, $cumulative['included_attempts']);
        $this->assertSame(['INC', 'CREDIT'], $results->pluck('result')->all());
        $this->assertSame([true, true], $results->pluck('outcome_complete')->all());
        $this->assertSame('INC', $event->fresh()->result_code);
    }

    #[Test]
    public function only_authoritative_drop_and_applied_withdrawal_outcomes_complete_missing_grades(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $numeric = $this->fixture();
        $this->releaseResult($numeric, '2.50');
        $drop = $this->fixture(term: $numeric['term'], student: $numeric['student']);
        $drop['course_enrollment']->update(['status' => CourseEnrollment::StatusDropped, 'is_current' => false, 'dropped_at' => now()]);

        $this->assertSame(AcademicAverageReadiness::GradesNotComplete, app(TermWeightedAverageProjection::class)->forStudentAndTerm($numeric['student'], $numeric['term'])['state']);

        CourseDropRecord::factory()->create([
            'enrollment_id' => $drop['course_enrollment']->enrollment_id,
            'course_enrollment_id' => $drop['course_enrollment']->id,
            'recorded_by' => $drop['registrar']->id,
        ]);
        $average = app(TermWeightedAverageProjection::class)->forStudentAndTerm($numeric['student'], $numeric['term']);
        $this->assertSame(AcademicAverageReadiness::Available, $average['state']);
        $this->assertSame('2.50', $average['value']);

        $withdrawn = $this->fixture(term: $numeric['term'], student: $numeric['student']);
        $withdrawn['course_enrollment']->update(['status' => CourseEnrollment::StatusWithdrawn, 'withdrawn_at' => now()]);
        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $numeric['student']->id,
            'term_id' => $numeric['term']->id,
            'enrollment_id' => $withdrawn['course_enrollment']->enrollment_id,
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'state' => StudentLifecycleChange::StateRecordedApproved,
        ]);
        $this->assertSame(AcademicAverageReadiness::GradesNotComplete, app(TermWeightedAverageProjection::class)->forStudentAndTerm($numeric['student'], $numeric['term'])['state']);

        $change->update(['state' => StudentLifecycleChange::StateApplied]);
        $results = app(OfficialCourseResultProjection::class)->forStudent($numeric['student']);
        $this->assertSame(['2.50', 'DRP', 'W'], $results->pluck('result')->all());
        $this->assertSame('2.50', app(CumulativeGwaProjection::class)->forStudent($numeric['student'])['value']);
    }

    #[Test]
    public function superseded_unreleased_registration_is_not_an_attempt_but_every_released_attempt_remains(): void
    {
        $first = $this->fixture();
        $released = $this->releaseResult($first, '5.00');
        $pending = $this->fixture(term: $first['term'], student: $first['student']);
        $replacement = $this->fixture(term: $first['term'], student: $first['student']);
        $pending['course_enrollment']->update(['is_current' => false, 'effective_until' => now()]);
        $replacement['course_enrollment']->update(['supersedes_course_enrollment_id' => $pending['course_enrollment']->id]);
        $first['course_enrollment']->update(['is_current' => false, 'effective_until' => now()]);
        $this->releaseResult($replacement, '1.00');

        $results = app(OfficialCourseResultProjection::class)->forStudent($first['student']);
        $average = app(TermWeightedAverageProjection::class)->forStudentAndTerm($first['student'], $first['term']);

        $this->assertSame([$released->id, $replacement['course_enrollment']->gradeRosterRow->outcomeEvents->sole()->id], $results->pluck('event.id')->all());
        $this->assertSame(AcademicAverageReadiness::Available, $average['state']);
        $this->assertSame('3.00', $average['value']);
        $this->assertSame(2, app(CumulativeGwaProjection::class)->forStudent($first['student'])['included_attempts']);
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
    public function past_owning_credit_outcomes_do_not_masquerade_as_missing_grades_for_advising(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $fixture = $this->fixture(termEndsOn: '2026-05-31');
        $effect = app(AcademicEnrollmentEffect::class);
        $enrollment = $fixture['course_enrollment']->enrollment;
        $before = $enrollment->only(['canonical_outcome', 'status', 'current_proposal_version_id', 'current_cor_version_id']);

        $this->assertSame(AcademicDecision::EffectAdvisingRequired, $effect->forStudent($fixture['student'])['effect']);

        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $fixture['student']->id,
            'term_id' => $fixture['term']->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        ProgramShiftCreditEntry::factory()->create([
            'student_lifecycle_change_id' => $change->id,
            'curriculum_entry_id' => $fixture['course_enrollment']->termOffering->curriculum_entry_id,
            'treatment' => ProgramShiftCreditEntry::TreatmentAccepted,
        ]);

        $this->assertSame(AcademicDecision::EffectAllowed, $effect->forStudent($fixture['student'])['effect']);
        $this->assertSame($before, $enrollment->fresh()->only(array_keys($before)));
        $this->assertSame(0, GradeOutcomeEvent::query()->count());
    }

    #[Test]
    public function same_term_corequisite_release_and_correction_open_idempotent_reviews_without_changing_registration(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $source = $this->fixture();
        $companion = $this->fixture(term: $source['term'], student: $source['student']);
        $sourceSpecification = $source['course_enrollment']->termOffering->curriculumEntry->courseSpecification;
        CourseRequirement::factory()->create([
            'course_specification_id' => $companion['course_enrollment']->termOffering->curriculumEntry->course_specification_id,
            'related_course_id' => $sourceSpecification->course_id,
            'rule_type' => CourseRequirement::TypeCorequisite,
            'state' => CourseRequirement::StateActive,
        ]);
        $case = $source['course_enrollment']->enrollment;
        $before = $case->only(['canonical_outcome', 'status', 'current_proposal_version_id', 'current_cor_version_id']);
        $courses = $case->courseEnrollments()->orderBy('id')->get()->map->only(['id', 'status', 'is_current', 'units_snapshot'])->all();

        $event = $this->releaseResult($source, '5.00');
        app(OpenRegistrationImpactReviewsForGradeOutcome::class)->execute($event->row, $event, $source['registrar']);

        $this->assertImpactReviewCount($case, 1);
        $corrected = app(RecordApprovedGradeCorrection::class)->execute(
            $event->row, '2.00',
            'Authorized correction of the concurrent course result.', $source['registrar'],
            'same-term-corequisite-correction',
        );
        app(RecordApprovedGradeCorrection::class)->execute(
            $event->row, '2.00',
            'Authorized correction of the concurrent course result.', $source['registrar'],
            'same-term-corequisite-correction',
        );

        $this->assertImpactReviewCount($case, 2);
        $this->assertSame(2, $corrected->outcomeEvents()->count());
        $this->assertSame($before, $case->fresh()->only(array_keys($before)));
        $this->assertSame($courses, $case->courseEnrollments()->orderBy('id')->get()->map->only(['id', 'status', 'is_current', 'units_snapshot'])->all());
    }

    #[Test]
    public function overlapping_case_reviews_stay_scoped_to_active_matching_requisites_and_the_same_learner(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $sourceTerm = Term::factory()->create([
            'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'state' => Term::StateActive,
        ]);
        $source = $this->fixture(term: $sourceTerm);
        $overlap = Term::factory()->create([
            'starts_on' => '2026-08-01', 'ends_on' => '2026-11-30', 'state' => Term::StateActive,
        ]);
        $affected = $this->pendingRegistrationCaseFor($source, $overlap);
        $earlier = $this->pendingRegistrationCaseFor($source, Term::factory()->create([
            'starts_on' => '2026-01-01', 'ends_on' => '2026-08-31',
        ]));
        $cancelled = $this->pendingRegistrationCaseFor($source);
        $cancelled->update(['canonical_outcome' => Enrollment::OutcomeCancelledByRegistrar, 'cancelled_at' => now()]);
        $inactiveRule = $this->pendingRegistrationCaseFor($source);
        $inactiveSpecification = $inactiveRule->courseEnrollments()->sole()->termOffering->curriculumEntry->course_specification_id;
        CourseRequirement::query()->where('course_specification_id', $inactiveSpecification)->update(['state' => 'RETIRED']);
        $equivalency = $this->pendingRegistrationCaseFor($source);
        $equivalentSpecification = $equivalency->courseEnrollments()->sole()->termOffering->curriculumEntry->course_specification_id;
        CourseRequirement::query()->where('course_specification_id', $equivalentSpecification)->update(['rule_type' => CourseRequirement::TypeEquivalency]);
        $unrelated = Enrollment::factory()->create([
            'credential_user_id' => $source['student']->user_id,
            'student_profile_id' => $source['student']->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $otherLearner = $this->fixture(term: $sourceTerm, specification: $source['course_enrollment']->termOffering->curriculumEntry->courseSpecification);
        $otherCase = $this->pendingRegistrationCaseFor($otherLearner, $overlap);
        $before = $affected->only(['canonical_outcome', 'status', 'current_proposal_version_id', 'current_cor_version_id']);

        $event = $this->releaseResult($source, '2.00');
        app(OpenRegistrationImpactReviewsForGradeOutcome::class)->execute($event->row, $event, $source['registrar']);

        $this->assertImpactReviewCount($affected, 1);
        foreach ([$earlier, $cancelled, $inactiveRule, $equivalency, $unrelated, $otherCase] as $unaffected) {
            $this->assertImpactReviewCount($unaffected, 0);
        }
        $this->assertSame($before, $affected->fresh()->only(array_keys($before)));
        $this->assertSame(CourseEnrollment::StatusActive, $affected->courseEnrollments()->sole()->status);
        $this->assertNull($otherLearner['course_enrollment']->fresh()->gradeRosterRow);
    }

    #[Test]
    public function future_academic_decisions_start_on_the_inclusive_school_date_without_reviving_expired_predecessors(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 15:59:59', 'UTC'));
        $fixture = $this->fixture(termEndsOn: '2027-03-31');
        $recorder = app(RecordAcademicDecision::class);
        $projection = app(AcademicEnrollmentEffect::class);
        $first = $recorder->execute(
            $fixture['student'], $fixture['term'], AcademicDecision::EffectBlocked,
            'BOARD-CURRENT', Carbon::parse('2026-10-27'), 'Current approved restriction.',
            Carbon::parse('2026-10-27'), null, $fixture['registrar'],
        );
        $successor = $recorder->execute(
            $fixture['student'], $fixture['term'], AcademicDecision::EffectAllowed,
            'BOARD-FUTURE', Carbon::parse('2026-10-27'), 'Approved temporary clearance.',
            Carbon::parse('2026-10-28'), Carbon::parse('2026-10-29'), $fixture['registrar'],
        );

        $this->assertSame('BOARD-CURRENT', $projection->forStudent($fixture['student'], $fixture['term'])['source']);
        $this->assertSame('Current approved restriction.', $first->fresh()->reason);
        $this->assertSame('2026-10-28', $successor->fresh()->effective_from->toDateString());

        $this->travelTo(Carbon::parse('2026-10-27 16:00:00', 'UTC'));
        $this->assertSame('BOARD-FUTURE', $projection->forStudent($fixture['student'], $fixture['term'])['source']);

        $this->travelTo(Carbon::parse('2026-10-29 15:59:59', 'UTC'));
        $this->assertSame(AcademicDecision::EffectAllowed, $projection->forStudent($fixture['student'], $fixture['term'])['effect']);

        $this->travelTo(Carbon::parse('2026-10-29 16:00:00', 'UTC'));
        $this->assertSame('Current released academic and lifecycle records', $projection->forStudent($fixture['student'], $fixture['term'])['source']);
        $this->assertSame(2, AcademicDecision::query()->where('student_profile_id', $fixture['student']->id)->count());
    }

    #[Test]
    public function term_decisions_override_student_wide_decisions_only_within_their_applicable_scope(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'Asia/Manila'));
        $fixture = $this->fixture(termEndsOn: '2027-03-31');
        $otherTerm = Term::factory()->create();
        $unrelatedTerm = Term::factory()->create();
        $recorder = app(RecordAcademicDecision::class);
        $projection = app(AcademicEnrollmentEffect::class);
        $global = $recorder->execute(
            $fixture['student'], null, AcademicDecision::EffectBlocked,
            'BOARD-GENERAL', today(), 'Student-wide restriction.',
            today(), null, $fixture['registrar'],
        );
        $termDecision = $recorder->execute(
            $fixture['student'], $fixture['term'], AcademicDecision::EffectAllowed,
            'BOARD-TERM', today(), 'Term-specific clearance.',
            today(), Carbon::parse('2026-10-28'), $fixture['registrar'],
        );
        $otherDecision = $recorder->execute(
            $fixture['student'], $otherTerm, AcademicDecision::EffectAdvisingRequired,
            'BOARD-OTHER-TERM', today(), 'Advising for another Term.',
            today(), null, $fixture['registrar'],
        );
        $this->assertSame('ACTIVE', $global->fresh()->state);

        $global->update(['state' => 'SUPERSEDED']);
        $this->assertSame('BOARD-GENERAL', $projection->forStudent($fixture['student'], $unrelatedTerm)['source']);
        $this->assertSame('BOARD-TERM', $projection->forStudent($fixture['student'], $fixture['term'])['source']);

        $recorder->execute(
            $fixture['student'], null, AcademicDecision::EffectPendingDecision,
            'BOARD-GENERAL-SUCCESSOR', today(), 'Named student-wide review.',
            today(), null, $fixture['registrar'],
        );
        $this->assertSame('ACTIVE', $termDecision->fresh()->state);
        $this->assertSame('ACTIVE', $otherDecision->fresh()->state);
        $this->assertSame('BOARD-TERM', $projection->forStudent($fixture['student'], $fixture['term'])['source']);
        $this->assertSame('BOARD-OTHER-TERM', $projection->forStudent($fixture['student'], $otherTerm)['source']);
        $this->assertSame('BOARD-GENERAL-SUCCESSOR', $projection->forStudent($fixture['student'], $unrelatedTerm)['source']);
        $this->assertSame('BOARD-GENERAL-SUCCESSOR', $projection->forStudent($fixture['student'])['source']);

        $this->travelTo(Carbon::parse('2026-10-29 12:00:00', 'Asia/Manila'));
        $this->assertSame('BOARD-GENERAL-SUCCESSOR', $projection->forStudent($fixture['student'], $fixture['term'])['source']);
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
    public function examination_dates_use_term_context_without_appearing_in_the_student_directory(): void
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
        Livewire::actingAs($fixture['registrar'])->test(GradesAndCompletion::class, ['viewTab' => 'academic-progress'])
            ->assertDontSee('Examination');
        Livewire::actingAs($academicHead)->test(AcademicApprovals::class)
            ->assertSee('Examination')
            ->assertSee('May 10, 2027')
            ->assertSee('May 15, 2027')
            ->assertSee('BOARD-CALENDAR-2026-03')
            ->assertSee('Class-level examination arrangements are not inferred.');

        Livewire::withQueryParams(['termId' => $fixture['term']->id])->actingAs($fixture['faculty'])->test(FacultySchedule::class)
            ->assertSee('Examination period:')->assertSee('BOARD-CALENDAR-2026-03');
        Livewire::withQueryParams([]);

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

        $classGradesUrl = GradesAndCompletion::getUrl(['viewTab' => 'grade-review']);
        Livewire::actingAs($fixture['registrar'])->test(ListGradeRosters::class)
            ->assertRedirect($classGradesUrl);
        Livewire::actingAs($fixture['registrar'])->test(GradesAndCompletion::class, ['viewTab' => 'grade-review'])
            ->assertSee($roster->section->code)
            ->assertSee($siblingRoster->section->code);

        Livewire::actingAs($this->staff(User::StaffRoleAcademicHead))->test(ListGradeRosters::class)
            ->assertCanSeeTableRecords([$roster, $siblingRoster]);

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

        $this->saveGradeRosterDraft($roster, $fixture['faculty'], [
            $roster->rows->sole()->id => ['final_result' => '2.00', 'inc_completion_note' => null],
        ]);
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
    public function head_student_record_breadcrumb_returns_to_a_permitted_record_list(): void
    {
        $fixture = $this->fixture();
        $head = $this->staff(User::StaffRoleAcademicHead);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($head)->withQueryParams(['viewTab' => 'academic-progress'])
            ->test(ViewStudentProfile::class, ['record' => $fixture['student']->id]);
        $breadcrumbs = $page->instance()->getBreadcrumbs();

        $this->assertArrayHasKey(StudentProfileResource::getUrl('index', ['viewTab' => 'academic-progress']), $breadcrumbs);
        $this->assertArrayNotHasKey(GradesAndCompletion::getUrl(['viewTab' => 'academic-progress']), $breadcrumbs);
    }

    #[Test]
    public function academic_notifications_link_to_the_relevant_class_and_released_student_result(): void
    {
        $fixture = $this->fixture();
        $fixture['student']->user->assignRole('student');
        Mail::fake();
        $event = $this->releaseResult($fixture, 'INC', 'Complete the remaining project.');
        $notifications = OperationalEvent::query()->where('related_record_type', GradeOutcomeEvent::class)->where('related_record_id', $event->id)->get();
        $studentNotice = $notifications->firstWhere('event_type', OperationalEvent::TypeGradeRosterReleasedEmail);
        $facultyNotice = $notifications->first(fn (OperationalEvent $notice): bool => (int) $notice->user_id === (int) $fixture['faculty']->id);
        $studentPath = '/student/academics?view=record#course-result-'.$event->row->course_enrollment_id;
        $facultyPath = '/admin/faculty-grade-roster?roster='.$event->row->grade_roster_id;

        $this->assertSame($studentPath, $studentNotice->payload['action_path']);
        $this->assertSame($facultyPath, $facultyNotice->payload['action_path']);
        $this->actingAs($fixture['student']->user)->get($studentPath)
            ->assertSee('id="course-result-'.$event->row->course_enrollment_id.'"', false);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($fixture['faculty'])->withQueryParams(['roster' => $event->row->grade_roster_id])
            ->test(FacultyGradeRoster::class)->assertSee('Complete the remaining project.');
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
            $incEvent, today()->addMonths(6),
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
            $incEventA->row, '2.00', 'Correction while submission pending.', $fixtureA['registrar'],
        );
        try {
            app(ReleaseIncCompletion::class)->execute($submissionA, $fixtureA['registrar']);
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
            $incEventB, today()->addMonths(3),
            'Intervening extension.', $fixtureB['registrar'],
        );
        try {
            app(ReleaseIncCompletion::class)->execute($submissionB, $fixtureB['registrar']);
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
        $releasedD1 = app(ReleaseIncCompletion::class)->execute($submissionD, $fixtureD['registrar']);
        $this->assertSame('2.00', $releasedD1->result_code);

        // Duplicate/concurrent release returns the identical event without extra outcome rows
        $releasedD2 = app(ReleaseIncCompletion::class)->execute($submissionD, $fixtureD['registrar']);
        $this->assertSame($releasedD1->id, $releasedD2->id);
        $this->assertSame(2, $incEventD->row->fresh()->outcomeEvents()->count());
    }

    #[Test]
    public function grade_correction_a_to_b_to_a_preserves_three_linked_events_with_idempotent_duplicate_commands(): void
    {
        $fixture = $this->fixture();
        $initialEvent = $this->releaseResult($fixture, '2.00');

        $correctedB = app(RecordApprovedGradeCorrection::class)->execute(
            $initialEvent->row, '2.25', 'Change from 2.00 to 2.25.', $fixture['registrar'],
        );
        $eventB = $correctedB->outcomeEvents()->latest('id')->firstOrFail();
        $this->assertSame($initialEvent->id, $eventB->predecessor_event_id);
        $this->assertSame('2.25', $correctedB->fresh()->current_outcome_code);

        // Duplicate command for B is idempotent
        app(RecordApprovedGradeCorrection::class)->execute(
            $initialEvent->row, '2.25', 'Change from 2.00 to 2.25.', $fixture['registrar'],
        );
        $this->assertSame(2, $initialEvent->row->fresh()->outcomeEvents()->count());

        // Correction back to A (2.00)
        $correctedA2 = app(RecordApprovedGradeCorrection::class)->execute(
            $initialEvent->row, '2.00', 'Change back from 2.25 to 2.00.', $fixture['registrar'],
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
            $initialEvent->row, '2.00', 'Change back from 2.25 to 2.00.', $fixture['registrar'],
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
        $this->assertSame('2.00', $roster->rows()->sole()->final_result);
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
    public function faculty_photo_reading_reuses_the_private_photo_and_requires_current_assigned_membership(): void
    {
        Storage::fake('local');
        $fixture = $this->fixture();
        $student = $fixture['student']->user;
        $student->assignRole('student');
        $path = 'profile-photos/'.$student->id.'/portrait.png';
        UploadedFile::fake()->image('portrait.png')->storeAs('profile-photos/'.$student->id, 'portrait.png', 'local');
        $student->forceFill(['avatar_path' => $path])->save();
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-PRIVATE-PHOTO');
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        app(ManageTeachingAssignment::class)->addCoFaculty($fixture['section'], $coFaculty, $fixture['registrar'], 'CO-PRIVATE-PHOTO');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $row = $roster->rows->sole();
        $url = route('grade-rosters.student-photo', ['roster' => $roster, 'row' => $row->id]);

        foreach ([$fixture['faculty'], $coFaculty, $fixture['registrar'], $this->staff(User::StaffRoleAcademicHead)] as $actor) {
            $response = $this->actingAs($actor)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')
                ->assertHeader('Cache-Control')->assertHeader('X-Content-Type-Options', 'nosniff');

            foreach (['private', 'no-store', 'max-age=0'] as $directive) {
                $this->assertStringContainsString($directive, $response->headers->get('Cache-Control'));
            }
        }
        $unassignedMultiRole = $this->staff(User::StaffRoleFaculty);
        $unassignedMultiRole->assignRole(User::StaffRoleRegistrar);
        $this->actingAs($unassignedMultiRole)->get($url)->assertOk();
        foreach ([$this->staff(User::StaffRoleFaculty), $student] as $actor) {
            $this->actingAs($actor)->get($url)->assertForbidden();
        }
        $this->actingAs($fixture['faculty'])->get(route('student.profile-photo'))->assertForbidden();
        $this->get(route('grade-rosters.student-photo', ['roster' => $roster, 'row' => PHP_INT_MAX]))->assertNotFound();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertSee($url, escape: false)->assertDontSee($path)->assertSee('Class details')->assertDontSee('Examination dates · information only');

        $student->forceFill(['avatar_path' => 'profile-photos/'.$fixture['faculty']->id.'/portrait.png'])->save();
        $this->actingAs($fixture['faculty'])->get($url)->assertNotFound();
        $student->forceFill(['avatar_path' => $path])->save();
        $fixture['course_enrollment']->update(['is_current' => false]);
        $this->get($url)->assertStatus(409);
        $this->assertSame(0, $roster->versions()->count());
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
        $page->set('data.rows.'.$rowKey.'.final_result', '2.00')->assertHasNoErrors()->assertSee('Draft saved')->assertDontSee('Save draft');
        $this->assertSame('2.00', $roster->rows()->sole()->final_result);
        $rowKey = array_key_first($page->get('data.rows'));
        $page->set('data.rows.'.$rowKey.'.final_result', 'INC')->set('data.rows.'.$rowKey.'.inc_completion_note', 'Complete the remaining project')
            ->assertHasNoErrors()->assertSet('draftSaveError', null);
        $this->assertSame('INC', $roster->rows()->sole()->final_result);
        $this->assertSame('Complete the remaining project', $roster->rows()->sole()->inc_completion_note);
    }

    #[Test]
    public function faculty_large_roster_browsing_preserves_drafts_and_submits_every_current_student(): void
    {
        $fixture = $this->fixture();
        for ($index = 1; $index < 100; $index++) {
            $this->addStudentToOffering($fixture);
        }
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-LARGE-ROSTER');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::withQueryParams(['roster' => $roster->id])->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class);
        $rows = $page->get('data.rows');
        $this->assertCount(100, $rows);
        foreach ($rows as &$row) {
            $row['final_result'] = '2.00';
        }
        unset($row);
        $firstKey = array_key_first($rows);
        $first = $rows[$firstKey];
        $last = $rows[array_key_last($rows)];

        $page->set('data.rows', $rows)->call('selectStudent', $first['id'])
            ->set('data.rows.'.$firstKey.'.final_result', 'INC')
            ->set('data.rows.'.$firstKey.'.inc_completion_note', 'Complete the remaining laboratory project.')
            ->call('goToStudentPage', 10)->assertSet('studentPage', 10)
            ->assertSee('Student 91 of 100')
            ->set('studentSearch', $last['student_number'])
            ->assertSee('1 of 100 students shown')
            ->set('studentSearch', 'no-matching-student-number')->assertSee('No matching students')
            ->assertSet('selectedStudentId', null)
            ->set('studentSearch', '')->call('selectStudent', $first['id'])
            ->assertSet('data.rows.'.$firstKey.'.final_result', 'INC')
            ->assertSet('data.rows.'.$firstKey.'.inc_completion_note', 'Complete the remaining laboratory project.')
            ->call('saveDraft')->assertHasNoErrors()->assertSet('draftSaveError', null);

        $this->assertSame('INC', $roster->rows()->findOrFail($first['id'])->final_result);
        $this->assertSame('Complete the remaining laboratory project.', $roster->rows()->findOrFail($first['id'])->inc_completion_note);
        $this->assertSame(100, $roster->rows()->whereNotNull('final_result')->count());

        $page->set('studentSearch', $last['student_number'])->callAction('submit')
            ->assertHasNoErrors()->assertNotified('Roster submitted for Registrar review');

        $this->assertSame(GradeRoster::StateSubmitted, $roster->fresh()->state);
        $this->assertSame(100, $roster->versions()->sole()->rows()->count());
        $this->assertSame('INC', $roster->versions()->sole()->rows()->where('grade_roster_row_id', $first['id'])->sole()->final_result);
        Livewire::withQueryParams([]);
    }

    #[Test]
    public function faculty_returned_filter_does_not_expand_edit_rights_or_reduce_resubmission_scope(): void
    {
        $fixture = $this->fixture();
        $this->addStudentToOffering($fixture);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-RETURNED-WORKSPACE');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        foreach ($roster->rows as $row) {
            $this->saveGradeRosterDraft($row->roster, $fixture['faculty'], [
                $row->id => ['final_result' => '2.00', 'inc_completion_note' => null],
            ]);
        }
        $submitted = app(SubmitGradeRoster::class)->execute($roster, $fixture['faculty']);
        $returnedId = $roster->rows->first()->id;
        app(ReturnGradeRoster::class)->execute($submitted, $fixture['registrar'], 'Recheck the final assessment result.', [$returnedId]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::withQueryParams(['roster' => $roster->id])->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertSet('needsChangesOnly', true)->assertSet('selectedStudentId', $returnedId)
            ->assertSee('1 of 2 students shown');
        $returnedKey = collect($page->get('data.rows'))->search(fn (array $row): bool => $row['id'] === $returnedId);
        $unchanged = collect($page->get('data.rows'))->first(fn (array $row): bool => $row['id'] !== $returnedId);
        $unchangedKey = collect($page->get('data.rows'))->search(fn (array $row): bool => $row['id'] === $unchanged['id']);
        $page->set('data.rows.'.$returnedKey.'.final_result', '2.25')
            ->assertSet('draftSaveError', null);
        $this->assertNotNull($roster->rows()->findOrFail($returnedId)->returned_at);
        $this->assertSame('Recheck the final assessment result.', $roster->rows()->findOrFail($returnedId)->return_reason);
        $page->set('data.rows.'.$returnedKey.'.final_result', '2.50')
            ->assertSet('draftSaveError', null)
            ->set('data.rows.'.$returnedKey.'.final_result', '2.25')
            ->set('needsChangesOnly', false)->call('selectStudent', $unchanged['id'])
            ->assertSee('Only results returned by the Registrar can be edited.')
            ->set('data.rows.'.$unchangedKey.'.final_result', '1.00')
            ->assertSet('draftSaveError', 'Only named returned rows may be changed.')
            ->assertSet('data.rows.'.$unchangedKey.'.final_result', '1.00')
            ->set('data.rows.'.$unchangedKey.'.final_result', '2.00')
            ->assertSet('draftSaveError', null)
            ->set('needsChangesOnly', true)->assertSet('data.rows.'.$returnedKey.'.final_result', '2.25')
            ->callAction('submit')->assertHasNoErrors()->assertNotified('Roster submitted for Registrar review');

        $this->assertSame(2, $roster->fresh()->current_version_number);
        $this->assertSame(2, $roster->versions()->where('version_number', 2)->sole()->rows()->count());
        $this->assertSame('2.00', $roster->rows()->findOrFail($unchanged['id'])->final_result);
        $this->assertSame('2.25', $roster->rows()->findOrFail($returnedId)->final_result);
        $this->assertNull($roster->rows()->findOrFail($returnedId)->returned_at);
        Livewire::withQueryParams([]);
    }

    #[Test]
    public function faculty_autosaves_incomplete_inc_drafts_but_requires_the_note_before_submission(): void
    {
        $fixture = $this->fixture();
        $this->addStudentToOffering($fixture);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-DRAFT-RECOVERY');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::withQueryParams(['roster' => $roster->id])->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class);
        $rows = $page->get('data.rows');
        $firstKey = array_key_first($rows);
        $first = $rows[$firstKey];
        $other = $rows[array_key_last($rows)];

        $page->call('selectStudent', $first['id'])
            ->set('data.rows.'.$firstKey.'.final_result', 'INC')
            ->assertSee('Draft saved')->assertSet('draftSaveError', null);
        $this->assertSame('INC', $roster->rows()->findOrFail($first['id'])->final_result);
        $this->assertNull($roster->rows()->findOrFail($first['id'])->inc_completion_note);
        $page->call('selectStudent', $other['id'])->callAction('submit')
            ->assertHasErrors(['data.rows.'.$firstKey.'.inc_completion_note'])
            ->assertSet('selectedStudentId', $first['id']);
        $this->assertSame(0, $roster->versions()->count());

        $page->set('data.rows.'.$firstKey.'.inc_completion_note', str_repeat('x', 1001))
            ->call('selectStudent', $other['id'])->call('saveDraft')
            ->assertHasErrors(['data.rows.'.$firstKey.'.inc_completion_note'])
            ->assertSet('selectedStudentId', $first['id'])
            ->set('data.rows.'.$firstKey.'.inc_completion_note', 'Complete the remaining project.')
            ->assertHasNoErrors()->assertSet('draftSaveError', null)->assertSee('Draft saved');

        $this->assertSame('Complete the remaining project.', $roster->rows()->findOrFail($first['id'])->inc_completion_note);
        $page->call('selectStudent', PHP_INT_MAX)->assertStatus(404);
        Livewire::withQueryParams([]);
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
            ->assertDontSee($fixture['section']->code)->assertSee('No classes match your filters');
        $fixture['course_enrollment']->update(['is_current' => false]);
        Livewire::withQueryParams(['classes' => 1])->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertSee('Roster needs update')->assertDontSee($fixture['student']->student_number)
            ->assertViewHas('classRows', fn ($rows): bool => $rows->sole()['count'] === null);
        Livewire::withQueryParams([]);
    }

    #[Test]
    public function faculty_class_worklist_paginates_eight_owned_classes_and_retains_filters_in_page_links(): void
    {
        $fixture = $this->fixture();
        $specification = $fixture['section']->termOffering->curriculumEntry->courseSpecification;
        $specification->update(['title' => 'Chooser pagination course']);
        $fixture['section']->update(['code' => 'CHOOSER-01']);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-CHOOSER-01');
        app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        for ($index = 2; $index <= 10; $index++) {
            $section = Section::factory()->create([
                'term_offering_id' => $fixture['section']->term_offering_id,
                'code' => 'CHOOSER-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'state' => Section::StateOpen,
            ]);
            app(ManageTeachingAssignment::class)->designate($section, $fixture['faculty'], $fixture['registrar'], 'ASSIGN-CHOOSER-'.$index);
            app(SynchronizeOfficialGradeRoster::class)->execute($section, $fixture['registrar']);
        }
        $otherTerm = $this->fixture(specification: $specification);
        $otherTerm['section']->update(['code' => 'CHOOSER-OTHER-TERM']);
        app(ManageTeachingAssignment::class)->designate($otherTerm['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-CHOOSER-OTHER-TERM');
        app(SynchronizeOfficialGradeRoster::class)->execute($otherTerm['section'], $fixture['registrar']);
        $privateSection = Section::factory()->create([
            'term_offering_id' => $fixture['section']->term_offering_id,
            'code' => 'CHOOSER-PRIVATE',
            'state' => Section::StateOpen,
        ]);
        app(ManageTeachingAssignment::class)->designate($privateSection, $otherTerm['faculty'], $fixture['registrar'], 'ASSIGN-CHOOSER-PRIVATE');
        app(SynchronizeOfficialGradeRoster::class)->execute($privateSection, $fixture['registrar']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['classes' => 1, 'term' => $fixture['term']->id, 'q' => 'Chooser'])
            ->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertViewHas('classRows', fn ($rows): bool => $rows->pluck('roster.section.code')->all() === [
                'CHOOSER-01', 'CHOOSER-02', 'CHOOSER-03', 'CHOOSER-04',
                'CHOOSER-05', 'CHOOSER-06', 'CHOOSER-07', 'CHOOSER-08',
            ])
            ->assertViewHas('classPaginator', function ($paginator) use ($fixture): bool {
                $this->assertSame(10, $paginator->total());
                $this->assertSame(8, $paginator->perPage());
                parse_str((string) parse_url($paginator->url(2), PHP_URL_QUERY), $query);
                $this->assertSame('1', $query['classes']);
                $this->assertSame((string) $fixture['term']->id, $query['term']);
                $this->assertSame('Chooser', $query['q']);
                $this->assertSame('2', $query['page']);

                return true;
            })
            ->assertDontSee('CHOOSER-OTHER-TERM')->assertDontSee('CHOOSER-PRIVATE');
        Livewire::withQueryParams(['classes' => 1, 'term' => $fixture['term']->id, 'q' => 'Chooser', 'page' => 2])
            ->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertViewHas('classRows', fn ($rows): bool => $rows->pluck('roster.section.code')->all() === ['CHOOSER-09', 'CHOOSER-10'])
            ->assertDontSee('CHOOSER-01')->assertDontSee('CHOOSER-PRIVATE');
        Livewire::withQueryParams(['classes' => 1, 'term' => $fixture['term']->id, 'q' => 'Chooser', 'page' => 99])
            ->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertSee('No classes on this page')->assertSee('Show first page')
            ->assertViewHas('classRows', fn ($rows): bool => $rows->isEmpty())
            ->assertViewHas('classPaginator', function ($paginator) use ($fixture): bool {
                parse_str((string) parse_url($paginator->url(1), PHP_URL_QUERY), $query);
                $this->assertSame('1', $query['classes']);
                $this->assertSame((string) $fixture['term']->id, $query['term']);
                $this->assertSame('Chooser', $query['q']);
                $this->assertSame('1', $query['page']);

                return true;
            });
        Livewire::withQueryParams([]);
    }

    #[Test]
    #[DataProvider('facultyClassSearchQueries')]
    public function faculty_class_search_finds_assigned_course_identity_and_term_context(string $search, bool $matches): void
    {
        $fixture = $this->fixture();
        $fixture['section']->update(['code' => 'SEARCH-SECTION-801']);
        $fixture['term']->update(['label' => 'Searchable semester']);
        $fixture['term']->academicYear->update(['label' => 'Searchable academic year']);
        $specification = $fixture['section']->termOffering->curriculumEntry->courseSpecification;
        $specification->update(['title' => 'Searchable Computing']);
        $specification->course->update(['code' => 'SEARCH-COURSE-801']);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-SEARCH-801');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['classes' => 1, 'q' => $search])
            ->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertViewHas('classRows', fn ($rows): bool => $rows->pluck('roster.id')->all() === ($matches ? [$roster->id] : []));
        Livewire::withQueryParams([]);
    }

    /** @return array<string, array{string, bool}> */
    public static function facultyClassSearchQueries(): array
    {
        return [
            'course title' => ['searchable computing', true],
            'course code' => ['search-course-801', true],
            'section code' => ['search-section-801', true],
            'term label' => ['searchable semester', true],
            'academic year' => ['searchable academic year', true],
            'literal percent' => ['%', false],
            'literal underscore' => ['_', false],
        ];
    }

    #[Test]
    public function faculty_class_worklist_shows_only_active_owned_and_cofaculty_assignments(): void
    {
        $fixture = $this->fixture();
        $fixture['section']->update(['code' => 'COFACULTY-OWNED-CLASS']);
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-WORKLIST-OWNER');
        $coFaculty = $this->staff(User::StaffRoleFaculty);
        app(ManageTeachingAssignment::class)->addCoFaculty($fixture['section'], $coFaculty, $fixture['registrar'], 'ASSIGN-WORKLIST-COFACULTY');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $other = $this->fixture();
        $other['section']->update(['code' => 'OTHER-FACULTY-PRIVATE-CLASS']);
        app(ManageTeachingAssignment::class)->designate($other['section'], $other['faculty'], $other['registrar'], 'ASSIGN-WORKLIST-PRIVATE');
        app(SynchronizeOfficialGradeRoster::class)->execute($other['section'], $other['registrar']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['classes' => 1])->actingAs($coFaculty)->test(FacultyGradeRoster::class)
            ->assertViewHas('classRows', fn ($rows): bool => $rows->pluck('roster.id')->all() === [$roster->id])
            ->assertViewHas('classTerms', fn ($terms): bool => $terms->pluck('id')->all() === [$fixture['term']->id])
            ->assertSee('COFACULTY-OWNED-CLASS')->assertDontSee('OTHER-FACULTY-PRIVATE-CLASS');
        Livewire::withQueryParams(['classes' => 1, 'q' => 'OTHER-FACULTY-PRIVATE-CLASS'])
            ->actingAs($coFaculty)->test(FacultyGradeRoster::class)
            ->assertSee('No classes match your filters')->assertViewHas('classRows', fn ($rows): bool => $rows->isEmpty());
        Livewire::withQueryParams(['classes' => 1])->actingAs($this->staff(User::StaffRoleFaculty))->test(FacultyGradeRoster::class)
            ->assertSee('No assigned classes yet')->assertDontSee('COFACULTY-OWNED-CLASS');
        Livewire::withQueryParams([]);
    }

    #[Test]
    public function faculty_class_worklist_explains_an_unassigned_term_filter_without_exposing_its_name(): void
    {
        $fixture = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($fixture['section'], $fixture['faculty'], $fixture['registrar'], 'ASSIGN-WORKLIST-REMOVED-TERM');
        app(SynchronizeOfficialGradeRoster::class)->execute($fixture['section'], $fixture['registrar']);
        $unassignedTerm = Term::factory()->create(['label' => 'PRIVATE-UNASSIGNED-TERM-NAME']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::withQueryParams(['classes' => 1, 'term' => $unassignedTerm->id])
            ->actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertSee('Term no longer assigned')->assertSee('No classes match your filters')->assertSee('Clear filters')
            ->assertDontSee('PRIVATE-UNASSIGNED-TERM-NAME')
            ->assertViewHas('classTerms', fn ($terms): bool => $terms->pluck('id')->all() === [$fixture['term']->id])
            ->assertViewHas('classRows', fn ($rows): bool => $rows->isEmpty());
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
        $draftRows = $page->get('data.rows');
        $draftRows[$rowKey]['final_result'] = '2.00';
        $page->set('data.rows', $draftRows);
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
        $page->assertSee('1 of 1 results recorded');
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
        Livewire::actingAs($fixture['registrar'])->withQueryParams(['viewTab' => 'grade-review'])
            ->test(GradesAndCompletion::class)
            ->assertTableColumnStateSet('completion', '0 of 0', $roster);
        $this->assertSame(1, $roster->rows()->count());
        $this->assertSame('1.25', $roster->rows()->sole()->final_result);
    }

    #[Test]
    public function academic_record_headings_show_the_year_level_each_semester_was_taken_in(): void
    {
        $firstTerm = Term::factory()->create([
            'label' => 'First Semester',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-10-31',
            'state' => Term::StateActive,
        ]);
        $firstYear = $this->fixture(term: $firstTerm);
        $firstYear['student']->user?->assignRole('student');
        $this->releaseResult($firstYear, '2.25');

        $secondTerm = Term::factory()->create([
            'label' => 'Second Semester',
            'starts_on' => '2027-01-04',
            'ends_on' => '2027-05-31',
            'state' => Term::StateActive,
        ]);
        $secondYear = $this->fixture(term: $secondTerm, student: $firstYear['student']);
        $secondYear['course_enrollment']->termOffering->curriculumEntry->update(['year_level' => 'Second Year']);
        $this->releaseResult($secondYear, '2.00');

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($firstYear['student']->user)->withQueryParams(['view' => 'record'])->test(StudentAcademics::class)
            ->assertSee('Second Year · Second Semester')
            ->assertSee('First Year · First Semester');
    }

    #[Test]
    public function registrar_workbench_separates_submissions_from_released_inc_work(): void
    {
        $pending = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($pending['section'], $pending['faculty'], $pending['registrar'], 'ASSIGN-QUEUE');
        $submitted = app(SynchronizeOfficialGradeRoster::class)->execute($pending['section'], $pending['registrar']);
        $this->saveGradeRosterDraft($submitted, $pending['faculty'], [
            $submitted->rows->sole()->id => ['final_result' => '2.00', 'inc_completion_note' => null],
        ]);
        $submitted = app(SubmitGradeRoster::class)->execute($submitted, $pending['faculty']);
        $incomplete = $this->fixture();
        $inc = $this->releaseResult($incomplete, 'INC', 'Complete the practical work.');
        $releasedRoster = $inc->row->roster;
        $numeric = $this->releaseResult($this->fixture(), '1.75');
        $draftFixture = $this->fixture();
        app(ManageTeachingAssignment::class)->designate($draftFixture['section'], $draftFixture['faculty'], $draftFixture['registrar'], 'ASSIGN-DRAFT');
        $draft = app(SynchronizeOfficialGradeRoster::class)->execute($draftFixture['section'], $draftFixture['registrar']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($pending['registrar'])->test(GradesAndCompletion::class)
            ->assertViewHas('overviewCounts', ['submitted' => 1, 'incomplete' => 1]);
        $inc->row->update(['is_current_membership' => false]);
        $draft->update(['state' => GradeRoster::StateReturned]);
        Livewire::actingAs($pending['registrar'])->test(GradesAndCompletion::class)
            ->assertViewHas('overviewCounts', ['submitted' => 1, 'incomplete' => 0]);
        $inc->row->update(['is_current_membership' => true]);

        Livewire::withQueryParams(['filters' => ['result_scope' => ['value' => 'all']]])
            ->actingAs($pending['registrar'])->test(GradesAndCompletion::class, ['viewTab' => 'inc-corrections'])
            ->assertSeeHtml('wire:key="inc-result-'.$inc->row->id.'"')
            ->assertSeeHtml('wire:key="inc-result-'.$numeric->row->id.'"');
        Livewire::withQueryParams(['filters' => ['state' => ['value' => GradeRoster::StateSubmitted]]])
            ->actingAs($pending['registrar'])->test(GradesAndCompletion::class, ['viewTab' => 'grade-review'])
            ->assertCanSeeTableRecords([$submitted])
            ->assertCanNotSeeTableRecords([$draft, $releasedRoster]);

        Livewire::actingAs($pending['registrar'])->test(GradesAndCompletion::class, ['viewTab' => 'grade-review'])
            ->assertCanSeeTableRecords([$submitted])
            ->assertCanNotSeeTableRecords([$releasedRoster]);
        Livewire::actingAs($pending['registrar'])->test(GradesAndCompletion::class, ['viewTab' => 'inc-corrections'])
            ->assertSeeHtml('wire:key="inc-result-'.$inc->row->id.'"')
            ->assertDontSeeHtml('wire:key="inc-result-'.$submitted->rows->sole()->id.'"')
            ->assertDontSeeHtml('wire:key="inc-result-'.$draft->rows->sole()->id.'"')
            ->assertDontSeeHtml('wire:key="inc-result-'.$numeric->row->id.'"')
            ->assertTableActionVisible('amendIncDeadline', $inc->row)
            ->filterTable('result_scope', 'all')
            ->assertSeeHtml('wire:key="inc-result-'.$inc->row->id.'"')
            ->assertSeeHtml('wire:key="inc-result-'.$numeric->row->id.'"')
            ->assertDontSeeHtml('wire:key="inc-result-'.$submitted->rows->sole()->id.'"')
            ->assertDontSeeHtml('wire:key="inc-result-'.$draft->rows->sole()->id.'"')
            ->assertTableActionVisible('recordCorrection', $numeric->row)
            ->searchTable($inc->row->roster->section->code)
            ->assertSeeHtml('wire:key="inc-result-'.$inc->row->id.'"')
            ->assertDontSeeHtml('wire:key="inc-result-'.$numeric->row->id.'"')
            ->searchTable('no-matching-released-result')
            ->assertSee('No released course results match')
            ->assertDontSeeHtml('aria-label="Selected course result"')
            ->assertDontSeeHtml('servitech-empty-state__art')
            ->call('resetTableSearch')
            ->assertSeeHtml('wire:key="inc-result-'.$inc->row->id.'"');
        Livewire::actingAs($pending['registrar'])->test(GradesAndCompletion::class, ['viewTab' => 'academic-progress'])
            ->assertCanSeeTableRecords([$pending['student'], $incomplete['student']])
            ->assertTableActionVisible('viewAcademicRecord', $pending['student']);
    }

    #[Test]
    public function registrar_grades_parent_orients_without_selecting_a_child_or_exposing_student_results(): void
    {
        $fixture = $this->fixture();
        $this->releaseResult($fixture, 'INC', 'Complete the practical work.');
        $fixture['registrar']->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $fixture['registrar']->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $response = $this->actingAs($fixture['registrar'])->get(GradesAndCompletion::getUrl())->assertOk()
            ->assertDontSee($fixture['student']->student_number)
            ->assertDontSee('Class submissions');
        foreach (array_keys(GradesAndCompletion::tabs()) as $tab) {
            $response->assertSee(GradesAndCompletion::getUrl(['viewTab' => $tab]), escape: false);
        }

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $currentItems = (new \DOMXPath($document))->query('//*[@id="fi-main-sidebar"]//a[@aria-current="page"]');
        foreach ($currentItems as $item) {
            foreach (array_keys(GradesAndCompletion::tabs()) as $tab) {
                $this->assertNotSame(GradesAndCompletion::getUrl(['viewTab' => $tab]), $item->getAttribute('href'));
            }
        }

        Livewire::actingAs($fixture['registrar'])->test(GradesAndCompletion::class)->assertSet('viewTab', 'overview');
        Livewire::actingAs($fixture['registrar'])->test(GradesAndCompletion::class, ['viewTab' => 'invalid'])->assertSet('viewTab', 'overview');
    }

    #[Test]
    public function inc_read_model_and_unofficial_output_agree_on_deadline_and_owned_drop_outcome(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $fixture = $this->fixture(termEndsOn: '2026-05-31');
        $fixture['student']->user->assignRole('student');
        $event = $this->releaseResult($fixture, 'INC', 'Complete the practical work.');
        app(AmendIncDeadline::class)->execute($event, Carbon::parse('2027-06-30'), 'Approved completion extension.', $fixture['registrar']);
        $drop = $this->fixture(term: $fixture['term'], student: $fixture['student']);
        $drop['course_enrollment']->update(['status' => CourseEnrollment::StatusDropped, 'is_current' => false, 'dropped_at' => now()]);
        CourseDropRecord::factory()->create([
            'enrollment_id' => $drop['course_enrollment']->enrollment_id,
            'course_enrollment_id' => $drop['course_enrollment']->id,
            'recorded_by' => $fixture['registrar']->id,
        ]);
        $result = app(OfficialCourseResultProjection::class)->forStudent($fixture['student'])->first();
        $this->assertSame('2027-06-30', $result['inc_deadline']->toDateString());
        $this->assertSame('Approved completion extension.', $result['inc_amendments']->sole()->reason);
        $this->actingAs($fixture['student']->user)
            ->get(route('student-academics.unofficial-record', $fixture['student']))
            ->assertOk()->assertSee('Jun 30, 2027')->assertSee('DRP')
            ->assertSee('Current academic average pending');
        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($fixture['student']->user)->test(StudentAcademics::class, ['academicView' => 'record'])
            ->assertSee('INC details and next step')->assertSee('Approved completion extension.')
            ->assertSee('DRP')->assertSee('Completion pending');
    }

    #[Test]
    public function registrar_academic_record_navigation_retains_its_origin_without_changing_other_entry_points(): void
    {
        $fixture = $this->fixture();
        $fixture['registrar']->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $fixture['registrar']->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $index = StudentProfileResource::getUrl('index', ['viewTab' => 'academic-progress']);
        $record = StudentProfileResource::getUrl('view', ['record' => $fixture['student'], 'viewTab' => 'academic-progress']);
        $children = GradesAndCompletion::getUrl(['viewTab' => 'inc-corrections']);
        $this->actingAs($fixture['registrar'])->get($index)->assertOk()->assertSee($children, escape: false)->assertSee($record, escape: false);
        $this->get($record)->assertOk()->assertSee($children, escape: false)
            ->assertSee(GradesAndCompletion::getUrl(['viewTab' => 'academic-progress']), escape: false)
            ->assertSee('Academic guidance');

        $this->app->forgetInstance('originalRequest');
        $this->get(StudentProfileResource::getUrl('view', ['record' => $fixture['student']]))
            ->assertOk()->assertSee(StudentProfileResource::getUrl('index'), escape: false);

        foreach ([
            'lifecycle' => StudentLifecycleChangeResource::getUrl('index'),
            'completion-tor' => CompletionAndTor::getUrl(),
            'history' => GradesAndCompletion::getUrl(['viewTab' => 'grade-review', ...GradesAndCompletion::classQueueParameters(['viewTab' => 'history'])]),
        ] as $tab => $destination) {
            $this->app->forgetInstance('originalRequest');
            $this->get(GradesAndCompletion::getUrl(['viewTab' => $tab]))->assertRedirect($destination);
        }

        $destinations = [
            [GradesAndCompletion::getUrl(['viewTab' => 'grade-review']), 'grade-review'],
            [CompletionAndTor::getUrl(['viewTab' => 'grade-review']), 'completion-tor'],
            [StudentLifecycleChangeResource::getUrl('index'), 'lifecycle'],
            [StudentLifecycleChangeResource::getUrl('create'), 'lifecycle'],
        ];

        foreach ($destinations as [$url, $tab]) {
            $this->app->forgetInstance('originalRequest');
            $response = $this->get($url)->assertOk()->assertSee($children, escape: false);
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $currentItems = (new \DOMXPath($document))->query('//*[@id="fi-main-sidebar"]//a[@aria-current="page"]');
            $this->assertCount(1, $currentItems);
            $this->assertSame(GradesAndCompletion::getUrl(['viewTab' => $tab]), $currentItems->item(0)->getAttribute('href'));
        }

        $this->app->forgetInstance('originalRequest');
        $this->get(TranscriptRequestResource::getUrl('index'))->assertRedirect(CompletionAndTor::getUrl());
    }

    #[Test]
    public function faculty_inc_completion_action_follows_the_inclusive_deadline_and_current_assignment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $fixture = $this->fixture(termEndsOn: '2025-10-26');
        $inc = $this->releaseResult($fixture, 'INC', 'Complete the remaining practical work.');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertActionVisible('submitIncCompletion');
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00', 'Asia/Manila'));
        Livewire::actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertActionHidden('submitIncCompletion');
        app(AmendIncDeadline::class)->execute($inc, Carbon::parse('2026-11-30'), 'Approved deadline extension.', $fixture['registrar']);
        Livewire::actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertActionVisible('submitIncCompletion');
        $inc->row->roster->teachingAssignment->update(['state' => ClassOfferingTeachingAssignment::StateReplaced]);
        Livewire::actingAs($fixture['faculty'])->test(FacultyGradeRoster::class)
            ->assertActionHidden('submitIncCompletion');
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
        $this->saveGradeRosterDraft($roster, $fixture['faculty'], [
            $roster->rows->sole()->id => ['final_result' => $result, 'inc_completion_note' => $incNote],
        ]);
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
    private function pendingRegistrationCaseFor(array $fixture, ?Term $dependentTerm = null): Enrollment
    {
        $sourceCourse = $fixture['course_enrollment']->termOffering->curriculumEntry->courseSpecification->course;
        $dependentTerm ??= Term::factory()->create([
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
