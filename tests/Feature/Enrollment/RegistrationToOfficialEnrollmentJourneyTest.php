<?php

namespace Tests\Feature\Enrollment;

use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Cor\BuildCorOutput;
use App\Actions\Enrollment\ApplyRegistrationAdjustment;
use App\Actions\Enrollment\CancelRegistrationCase;
use App\Actions\Enrollment\ConfirmRegistrationProposal;
use App\Actions\Enrollment\FinalizeOfficialEnrollment;
use App\Actions\Enrollment\IssueRegistrationProposal;
use App\Actions\Enrollment\PlaceRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\RecordCourseDrop;
use App\Actions\Enrollment\RecordLateEnrollmentReopenAuthority;
use App\Actions\Enrollment\RecordRegistrationAdjustmentFinanceConfirmation;
use App\Actions\Enrollment\RecordRegistrationLateAuthority;
use App\Actions\Enrollment\RecordRegistrationSourceImpactReview;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\ReleaseExpiredEnrollmentReservations;
use App\Actions\Enrollment\ReopenRegistrationCase;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Actions\Finance\CreateFeePlanDraft;
use App\Actions\Finance\CreateSuccessorFeePlan;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Finance\RecordApprovedCoverage;
use App\Actions\Finance\RecordAuthorizedIndividualAssessment;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Actions\Finance\UpdateFeePlanDraft;
use App\Filament\Applicant\Pages\Dashboard as ApplicantDashboard;
use App\Filament\Student\Pages\Enrollment as StudentEnrollmentPage;
use App\Mail\OfficialEnrollmentMail;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentGateResult;
use App\Models\EnrollmentSeatReservation;
use App\Models\FeePlan;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationAdjustmentFinanceConfirmation;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationLateAuthority;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationToOfficialEnrollmentJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['applicant', 'student', User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleFaculty, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Mail::fake();
        Storage::fake('local');
    }

    public function test_ready_applicant_reaches_official_enrollment_and_immutable_cor_without_early_student_identity(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section, $timetable, $curriculum] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);

        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);

        $this->assertNull($case->student_profile_id);
        $this->assertFalse($application->user->hasRole('student'));
        $this->assertSame(1, Enrollment::query()->where('credential_user_id', $application->user_id)->where('term_id', $term->id)->count());
        $this->assertTrue($case->is(app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user)));

        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        $proposal = app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        $proposal = app(PlaceRegistrationProposal::class)->execute($proposal, $registrar);
        app(PlaceRegistrationProposal::class)->execute($proposal, $registrar);

        $this->assertSame(RegistrationProposalVersion::StateConfirmed, $proposal->state);
        $this->assertSame($timetable->id, $proposal->published_timetable_version_id);
        $this->assertSame($curriculum->id, $proposal->curriculum_version_id);
        $this->assertSame(0, CourseEnrollment::query()->count());
        $this->assertSame(0, StudentScheduleBinding::query()->count());
        $this->assertSame(1, $proposal->items()->firstOrFail()->reservation()->count());

        $plan = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'amount' => '1000.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic approved fee authority');
        $assessment = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
        $evidence = app(SubmitPaymentEvidence::class)->execute(
            $assessment->termAccount,
            $application->user,
            UploadedFile::fake()->image('receipt.jpg'),
            '1000.00',
            'bank_transfer',
            now()->subMinute(),
            'SYN-PAYMENT-001',
        );
        app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $accounting,
            '1000.00',
            'SYN-INDEPENDENT-CHECK-001',
        );
        $this->assertSame('Cleared', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['state']);

        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
        $again = app(FinalizeOfficialEnrollment::class)->execute($official, $registrar);

        $this->assertTrue($official->is($again));
        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame(1, StudentProfile::query()->where('user_id', $application->user_id)->count());
        $this->assertMatchesRegularExpression('/^SIA-\d{4}-\d{4}$/', $official->studentProfile->student_number);
        $this->assertTrue($application->user->fresh()->hasRole('student'));
        $this->assertTrue($application->user->fresh()->hasRole('applicant'));
        $this->assertSame(1, CourseEnrollment::query()->where('enrollment_id', $official->id)->where('is_current', true)->count());
        $this->assertSame(0, EnrollmentGateResult::query()->where('enrollment_id', $official->id)->count());
        $this->assertSame(0, StudentScheduleBinding::query()->count());
        $this->assertSame(1, $official->corVersions()->count());
        Mail::assertQueued(OfficialEnrollmentMail::class);

        $corBefore = app(BuildCorOutput::class)->forEnrollment($official, $application->user);
        $section->update(['code' => 'CHANGED-AFTER-FINALIZATION']);
        $assessment->update(['total' => '9999.00']);
        $corAfter = app(BuildCorOutput::class)->forEnrollment($official->fresh(), $application->user);

        $this->assertSame($corBefore['state'], $corAfter['state']);
        $this->assertSame('1000.00', $corAfter['fees'][0]['amount']);
        $this->assertSame($timetable->id, $corAfter['schedule_version']);
    }

    public function test_unavailable_assessment_never_falls_back_to_zero_and_fee_plan_publication_is_versioned(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        try {
            app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
            $this->fail('Missing fee authority must remain Unavailable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
        }
        $unavailable = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh());
        $this->assertSame('Unavailable', $unavailable['state']);
        $this->assertNull($unavailable['total']);
        $this->assertNull($unavailable['balance']);

        $draft = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'NO-PAYMENT', 'label' => 'Explicit no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $published = $this->publishFeePlan($draft, $accounting, 'Synthetic no-payment authority');
        $successor = app(CreateSuccessorFeePlan::class)->execute($published, $accounting);
        $this->updateFeePlanDraft(
            $successor,
            [['code' => 'NO-PAYMENT-V2', 'label' => 'Successor no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $successor = $this->publishFeePlan($successor->fresh(), $accounting, 'Synthetic successor authority');
        $assessment = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);

        $this->assertSame(FeePlan::StateSuperseded, $published->fresh()->state);
        $this->assertSame(FeePlan::StatePublished, $successor->state);
        $this->assertSame($published->id, $successor->supersedes_fee_plan_id);
        $this->assertSame('PublishedFeePlan', $assessment->assessment_basis);
        $this->assertSame('Cleared', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['state']);
        $this->assertSame('NoPaymentRequired', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['basis']);
    }

    public function test_cancel_reopen_and_stale_proposal_paths_are_fail_closed(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $cancelled = app(CancelRegistrationCase::class)->execute($case, $application->user, 'Learner changed plans.');
        $reopened = app(ReopenRegistrationCase::class)->execute($cancelled, $registrar, 'Authorized same-case recovery.', 'SYN-REOPEN-001');

        $this->assertSame($case->id, $reopened->id);
        $this->assertSame(4, $reopened->registrationEvents()->count());
        $this->assertTrue($reopened->registrationEvents()->where('event_type', 'ReopenImpactPreviewed')->exists());

        $expectedVersion = $reopened->lock_version;
        $reopened->update(['lock_version' => $expectedVersion + 1]);

        try {
            app(PrepareRegistrationProposal::class)->execute($reopened, $application->user, [$section->id], $reopened->lock_version);
            $this->fail('Learners must not select arbitrary Class Offerings.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $reopened->proposalVersions()->count());
        }

        $this->expectException(ValidationException::class);
        app(PrepareRegistrationProposal::class)->execute($reopened, $registrar, [$section->id], $expectedVersion);
    }

    public function test_after_cutoff_reopen_requires_exact_unused_authority_for_the_same_case(): void
    {
        [$application, $term] = $this->readyApplicant();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $cancelled = app(CancelRegistrationCase::class)->execute($case, $application->user, 'Learner cancelled before confirmation.');
        CalendarEvent::query()
            ->where('term_id', $term->id)
            ->where('process_key', CalendarEvent::ProcessEnrollment)
            ->update(['end_at' => now()->subMinute()]);

        try {
            app(ReopenRegistrationCase::class)->execute(
                $cancelled,
                $registrar,
                'Late same-case recovery.',
                'SYN-LATE-REOPEN-001',
            );
            $this->fail('A terminal case cannot reopen after the final cutoff without exact authority.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('late_authority', $exception->errors());
        }

        $authority = app(RecordLateEnrollmentReopenAuthority::class)->execute(
            $cancelled,
            $registrar,
            'SYN-LATE-REOPEN-001',
            'Registrar approved exact-Term late enrollment recovery.',
        );
        $reopened = app(ReopenRegistrationCase::class)->execute(
            $cancelled,
            $registrar,
            'Late same-case recovery.',
            'SYN-LATE-REOPEN-001',
            $cancelled->lock_version,
            $authority,
        );

        $this->assertSame($case->id, $reopened->id);
        $this->assertSame(Enrollment::OutcomeInProgress, $reopened->canonical_outcome);
        $cancelledAgain = app(CancelRegistrationCase::class)->execute($reopened, $registrar, 'Second terminal state.');

        try {
            app(ReopenRegistrationCase::class)->execute(
                $cancelledAgain,
                $registrar,
                'Attempted authority reuse.',
                'SYN-LATE-REOPEN-001',
                $cancelledAgain->lock_version,
                $authority,
            );
            $this->fail('A late-enrollment authority must be single-use.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('late_authority', $exception->errors());
        }
    }

    public function test_standard_curriculum_requires_the_complete_exact_term_offering_set(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$firstSection, $timetable, $curriculum] = $this->publishedOffering($application, $term);
        $secondEntry = CurriculumEntry::factory()->for($curriculum)->create();
        $secondOffering = TermOffering::factory()->for($term)->for($secondEntry)->create([
            'state' => TermOffering::StateScheduled,
        ]);
        $secondSection = Section::factory()->for($secondOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $secondSection->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 3,
        ]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);

        try {
            app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$firstSection->id], $case->lock_version);
            $this->fail('Standard Curriculum must not silently omit an expected exact-Term offering.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sections', $exception->errors());
        }

        $proposal = app(PrepareRegistrationProposal::class)->execute(
            $case->fresh(),
            $registrar,
            [$firstSection->id, $secondSection->id],
            $case->fresh()->lock_version,
        );

        $this->assertCount(2, $proposal->items);
        $this->assertEqualsCanonicalizing(
            [$firstSection->id, $secondSection->id],
            $proposal->items->pluck('section_id')->all(),
        );
    }

    public function test_learner_self_cancellation_stops_at_confirmation_and_registrar_releases_capacity(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        try {
            app(CancelRegistrationCase::class)->execute($case->fresh(), $application->user, 'Too late for self-cancellation.');
            $this->fail('A learner must not self-cancel after confirming the proposal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }

        $cancelled = app(CancelRegistrationCase::class)->execute(
            $case->fresh(),
            $registrar,
            'Registrar recorded post-confirmation cancellation.',
        );

        $this->assertSame(Enrollment::OutcomeCancelled, $cancelled->canonical_outcome);
        $this->assertSame(
            EnrollmentSeatReservation::StatusReleased,
            $proposal->items()->firstOrFail()->reservation()->value('status'),
        );
    }

    public function test_applicant_can_cancel_an_unconfirmed_case_from_the_owned_workspace(): void
    {
        [$application, $term] = $this->readyApplicant();
        app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($application->user->fresh())
            ->test(ApplicantDashboard::class)
            ->assertActionVisible('cancelRegistration')
            ->callAction('cancelRegistration', data: [
                'reason' => 'Learner cancelled before proposal confirmation.',
            ])
            ->assertNotified('Enrollment cancelled');

        $this->assertDatabaseHas('enrollments', [
            'credential_user_id' => $application->user_id,
            'term_id' => $term->id,
            'canonical_outcome' => Enrollment::OutcomeCancelled,
        ]);
    }

    public function test_overlapping_current_timetable_placement_fails_without_partial_reservations(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $entry = $section->termOffering->curriculumEntry;
        $otherEntry = CurriculumEntry::factory()->for($entry->curriculumVersion)->create();
        $otherOffering = TermOffering::factory()->for($term)->for($otherEntry)->create(['state' => TermOffering::StateScheduled]);
        $otherSection = Section::factory()->for($otherOffering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $otherSection->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
        ]);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute(
            $case,
            $registrar,
            [$section->id, $otherSection->id],
            $case->lock_version,
        );
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);

        try {
            app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
            $this->fail('Overlapping published meetings must fail atomically.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('conflict', $exception->errors());
        }

        $this->assertSame(0, $case->seatReservations()->count());
    }

    public function test_registration_case_backfill_is_idempotent_and_stops_on_ambiguous_legacy_status(): void
    {
        $legacy = Enrollment::factory()->create([
            'status' => 'pending_review',
            'student_type' => 'regular',
        ]);
        DB::table('enrollments')->where('id', $legacy->id)->update([
            'credential_user_id' => null,
            'admission_application_id' => null,
            'case_reference' => null,
            'selection_basis' => null,
            'canonical_outcome' => null,
        ]);
        $migration = require database_path('migrations/2026_08_19_144604_backfill_canonical_registration_case_fields.php');
        $migration->up();
        $migration->up();

        $legacy->refresh();
        $this->assertSame($legacy->studentProfile->user_id, $legacy->credential_user_id);
        $this->assertSame(Enrollment::SelectionStandardCurriculum, $legacy->selection_basis);
        $this->assertSame(Enrollment::OutcomeInProgress, $legacy->canonical_outcome);
        $this->assertSame(sprintf('REG-LEGACY-%08d', $legacy->id), $legacy->case_reference);

        $ambiguous = Enrollment::factory()->create([
            'status' => 'withdrawn',
            'student_type' => 'regular',
        ]);
        DB::table('enrollments')->where('id', $ambiguous->id)->update([
            'credential_user_id' => null,
            'case_reference' => null,
            'selection_basis' => null,
            'canonical_outcome' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $migration->up();
    }

    public function test_student_number_allocation_is_unique_across_two_first_finalizations(): void
    {
        [$first] = $this->officialEnrollment();
        [$second] = $this->officialEnrollment();

        $this->assertNotSame($first->studentProfile->student_number, $second->studentProfile->student_number);
        $this->assertSame(2, StudentProfile::query()
            ->whereIn('id', [$first->student_profile_id, $second->student_profile_id])
            ->distinct('student_number')
            ->count('student_number'));
    }

    public function test_continuing_student_uses_the_same_exact_term_case_with_controlled_selection_and_late_authority(): void
    {
        $profile = StudentProfile::factory()->create();
        $profile->user->update(['status' => User::StatusActive]);
        $profile->user->assignRole('student');
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        CalendarEvent::factory()->for($term)->create([
            'process_key' => CalendarEvent::ProcessEnrollment,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        try {
            app(StartRegistrationCase::class)->forContinuingStudent(
                $profile,
                $term,
                $profile->user,
                Enrollment::SelectionIndividuallyAdvised,
                'RegistrarAssisted',
                'FORGED-ASSISTED-START',
            );
            $this->fail('A learner cannot forge a Registrar-assisted start method.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Enrollment::query()->where('credential_user_id', $profile->user_id)->count());
        }
        CalendarEvent::factory()->for($term)->create([
            'process_key' => CalendarEvent::ProcessEnrollment,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        $case = app(StartRegistrationCase::class)->forContinuingStudent(
            $profile,
            $term,
            $registrar,
            Enrollment::SelectionIndividuallyAdvised,
            'RegistrarAssisted',
            'SYN-ASSISTED-001',
        );
        $same = app(StartRegistrationCase::class)->forContinuingStudent($profile, $term, $profile->user);

        $this->assertTrue($case->is($same));
        $this->assertSame($profile->id, $case->student_profile_id);
        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $case->selection_basis);
        $this->assertSame('RegistrarAssisted', $case->start_method);
        $this->assertSame(1, StudentProfile::query()->where('user_id', $profile->user_id)->count());

        $lateTerm = Term::factory()->create(['state' => Term::StateActive]);
        $late = app(StartRegistrationCase::class)->forContinuingStudent(
            $profile,
            $lateTerm,
            $registrar,
            Enrollment::SelectionStandardCurriculum,
            'LateAuthority',
            'SYN-LATE-001',
        );

        $this->assertSame('LateAuthority', $late->start_method);
        $this->assertSame(2, Enrollment::query()->where('credential_user_id', $profile->user_id)->count());
    }

    public function test_continuing_student_finalization_reuses_the_permanent_identity_and_number(): void
    {
        $profile = StudentProfile::factory()->create();
        $profile->user->update(['status' => User::StatusActive]);
        $profile->user->assignRole('student');
        $studentNumber = $profile->student_number;
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        CalendarEvent::factory()->for($term)->create([
            'process_key' => CalendarEvent::ProcessEnrollment,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
        [$section] = $this->publishedOfferingForProgram($profile->program, $term, $profile->curriculumVersion);
        $case = app(StartRegistrationCase::class)->forContinuingStudent(
            $profile,
            $term,
            $registrar,
            Enrollment::SelectionIndividuallyAdvised,
            'RegistrarAssisted',
            'SYN-CONTINUING-001',
        );
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute(
            $proposal->fresh(),
            $profile->user,
            $registrar,
            'SYN-ASSISTED-CONFIRMATION-001',
        );
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $plan = $this->createFeePlanDraft(
            $profile->program,
            $term,
            [['code' => 'NO-PAYMENT', 'label' => 'Explicit no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic continuing Student fee authority');
        app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);

        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
        $again = app(FinalizeOfficialEnrollment::class)->execute($official, $registrar);

        $this->assertTrue($official->is($again));
        $this->assertSame($profile->id, $official->student_profile_id);
        $this->assertSame($studentNumber, $official->studentProfile->student_number);
        $this->assertSame(1, StudentProfile::query()->where('user_id', $profile->user_id)->count());
        $this->assertSame(1, $official->courseEnrollments()->where('is_current', true)->count());
        $this->assertSame(1, $official->corVersions()->count());
    }

    public function test_continuing_proposal_uses_the_recorded_curriculum_and_released_result_rules(): void
    {
        $profile = StudentProfile::factory()->create();
        $profile->user->update(['status' => User::StatusActive]);
        $profile->user->assignRole('student');
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        CalendarEvent::factory()->for($term)->create([
            'process_key' => CalendarEvent::ProcessEnrollment,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
        [$section] = $this->publishedOfferingForProgram($profile->program, $term, $profile->curriculumVersion);
        $case = app(StartRegistrationCase::class)->forContinuingStudent(
            $profile,
            $term,
            $registrar,
            Enrollment::SelectionIndividuallyAdvised,
            'RegistrarAssisted',
            'SYN-ELIGIBILITY-001',
        );
        CourseRequirement::factory()->create([
            'course_specification_id' => $section->termOffering->curriculumEntry->course_specification_id,
            'minimum_grade' => '3.0000',
        ]);

        try {
            app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
            $this->fail('A missing released prerequisite result must block the proposal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sections', $exception->errors());
        }

        $this->assertSame(0, $case->proposalVersions()->count());
    }

    public function test_failed_finalization_creates_no_partial_student_registration_cor_or_number(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);

        try {
            app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
            $this->fail('Finalization without placement and assessment authority must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('readiness', $exception->errors());
        }

        $this->assertSame(0, StudentProfile::query()->where('user_id', $application->user_id)->count());
        $this->assertFalse($application->user->fresh()->hasRole('student'));
        $this->assertSame(0, CourseEnrollment::query()->where('enrollment_id', $case->id)->count());
        $this->assertSame(0, $case->corVersions()->count());
        $this->assertDatabaseCount('student_number_sequences', 0);
    }

    public function test_assessment_requires_current_confirmed_placement_and_individual_authority(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        $plan = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'FIXED', 'label' => 'Fixed exact-Term charge', 'amount' => '100.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic ordinary authority');

        try {
            app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
            $this->fail('An unplaced proposal must not produce an assessment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('placement', $exception->errors());
        }
        $this->assertSame(0, Assessment::query()->where('enrollment_id', $case->id)->count());

        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        try {
            $this->recordIndividualAssessment(
                $case->fresh(),
                $accounting,
                'SYN-UNAUTHORIZED-INDIVIDUAL-001',
                [['code' => 'BYPASS', 'label' => 'Must not bypass the ordinary plan', 'amount' => '0.00']],
            );
            $this->fail('A Standard Curriculum case must use its Published Fee Plan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
        }

        $this->assertSame(0, Assessment::query()->where('enrollment_id', $case->id)->count());
    }

    public function test_unresolved_source_impact_and_stale_placement_block_atomic_finalization(): void
    {
        [$case, $application, , $registrar] = $this->caseReadyForFinalization();
        $opened = app(RecordRegistrationSourceImpactReview::class)->open(
            $case,
            $registrar,
            RecordRegistrationSourceImpactReview::SourceAcademicResult,
            'released-result:SYN-001',
            'A released result changed after proposal confirmation.',
        );

        $this->assertFalse(app(RegistrationReadinessQuery::class)->for($case->fresh())['eligibility']);
        try {
            app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
            $this->fail('An unresolved source-impact review must block finalization.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('readiness', $exception->errors());
        }
        $this->assertSame(0, StudentProfile::query()->where('user_id', $application->user_id)->count());

        app(RecordRegistrationSourceImpactReview::class)->resolve(
            $case,
            $opened,
            $registrar,
            'Registrar revalidated the same authoritative proposal.',
        );
        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);
        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);

        [$staleCase, $staleApplication, $staleSection, $staleRegistrar] = $this->caseReadyForFinalization();
        $staleSection->update(['state' => Section::StateClosed]);
        try {
            app(FinalizeOfficialEnrollment::class)->execute($staleCase->fresh(), $staleRegistrar);
            $this->fail('A section change after placement must fail finalization atomically.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('placement', $exception->errors());
        }
        $this->assertSame(0, StudentProfile::query()->where('user_id', $staleApplication->user_id)->count());
        $this->assertSame(0, $staleCase->courseEnrollments()->count());
        $this->assertSame(0, $staleCase->corVersions()->count());
        $this->assertSame(Section::StateClosed, $staleSection->fresh()->state);
    }

    public function test_proposal_supersession_preserves_source_and_confirmation_history(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $case = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $application->user,
            Enrollment::SelectionIndividuallyAdvised,
        );
        $first = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($first, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($first->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($first->fresh(), $registrar);
        $reservation = $first->items()->firstOrFail()->reservation()->firstOrFail();
        $assessment = $this->recordIndividualAssessment(
            $case->fresh(),
            $accounting,
            'SYN-SUPERSEDED-ASSESSMENT-001',
            [['code' => 'AUTH', 'label' => 'Superseded obligation', 'amount' => '0.00']],
        );
        $firstHash = $first->fresh()->content_hash;
        $replacement = Section::factory()->for($section->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 2,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
        ]);

        $second = app(PrepareRegistrationProposal::class)->execute(
            $case->fresh(),
            $registrar,
            [$replacement->id],
            $case->fresh()->lock_version,
        );

        $this->assertSame(RegistrationProposalVersion::StateSuperseded, $first->fresh()->state);
        $this->assertSame($first->id, $second->supersedes_version_id);
        $this->assertSame(2, $second->version);
        $this->assertNotNull($first->confirmation()->first());
        $this->assertSame($firstHash, $first->fresh()->content_hash);
        $this->assertSame(EnrollmentSeatReservation::StatusReleased, $reservation->fresh()->status);
        $this->assertSame(Assessment::StateSuperseded, $assessment->fresh()->state);
        $this->assertSame('Unavailable', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['state']);

        $this->expectException(LogicException::class);
        $first->fresh()->update(['content_hash' => hash('sha256', 'rewritten')]);
    }

    public function test_expiry_releases_capacity_once_and_returns_the_case_to_actionable_placement(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $reservation = $proposal->items()->firstOrFail()->reservation()->firstOrFail();
        $reservation->update(['deadline' => now()->subMinute()]);

        $first = app(ReleaseExpiredEnrollmentReservations::class)->execute();
        $second = app(ReleaseExpiredEnrollmentReservations::class)->execute();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertFalse(app(RegistrationReadinessQuery::class)->for($case->fresh())['placement']);
    }

    public function test_capacity_shortage_saves_no_partial_placement_and_recovers_through_a_successor_proposal(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$fullSection, $timetable] = $this->publishedOffering($application, $term);
        $fullSection->update(['capacity' => 1]);
        CourseEnrollment::factory()->create([
            'enrollment_id' => Enrollment::factory()->create(['term_id' => $term->id])->id,
            'term_offering_id' => $fullSection->term_offering_id,
            'section_id' => $fullSection->id,
            'published_timetable_version_id' => $timetable->id,
        ]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$fullSection->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);

        try {
            app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
            $this->fail('A full section must not create a partial reservation.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                "{$fullSection->code} has no remaining capacity. Registrar must choose a replacement.",
                $exception->errors()['capacity'][0],
            );
        }

        $this->assertSame(0, EnrollmentSeatReservation::query()->where('enrollment_id', $case->id)->count());
        $this->assertContains('Complete current placement', app(RegistrationReadinessQuery::class)->for($case->fresh())['blockers']);

        $replacement = Section::factory()->for($fullSection->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 1,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
        ]);
        $successor = app(PrepareRegistrationProposal::class)->execute(
            $case->fresh(),
            $registrar,
            [$replacement->id],
            $case->fresh()->lock_version,
        );
        app(IssueRegistrationProposal::class)->execute($successor, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($successor->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($successor->fresh(), $registrar);

        $this->assertSame(RegistrationProposalVersion::StateSuperseded, $proposal->fresh()->state);
        $this->assertSame($replacement->id, $successor->fresh()->items()->firstOrFail()->reservation()->value('section_id'));
        $this->assertTrue(app(RegistrationReadinessQuery::class)->for($case->fresh())['placement']);
    }

    public function test_inactive_cross_owner_cross_term_and_stale_actions_are_denied_without_partial_state(): void
    {
        [$application, $term] = $this->readyApplicant();
        $wrongTerm = Term::factory()->create(['state' => Term::StateActive]);
        $other = User::factory()->create(['status' => User::StatusActive]);
        $other->assignRole('applicant');
        $application->user->update(['status' => User::StatusInactive]);

        try {
            app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user->fresh());
            $this->fail('An inactive Applicant must not start registration.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Enrollment::query()->where('credential_user_id', $application->user_id)->count());
        }

        $application->user->update(['status' => User::StatusActive]);

        foreach ([[$application, $term, $other], [$application, $wrongTerm, $application->user->fresh()]] as [$source, $targetTerm, $actor]) {
            try {
                app(StartRegistrationCase::class)->forReadyApplicant($source, $targetTerm, $actor);
                $this->fail('Cross-owner or cross-Term registration must fail closed.');
            } catch (AuthorizationException|ValidationException) {
                $this->assertSame(0, Enrollment::query()->where('credential_user_id', $application->user_id)->count());
            }
        }

        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);

        try {
            app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, [$section->id], 0);
            $this->fail('A stale case token must not create another proposal.');
        } catch (ValidationException) {
            $this->assertSame(1, $case->proposalVersions()->count());
        }

        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);

        try {
            app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $other);
            $this->fail('A non-owning learner must not confirm the proposal.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $proposal->confirmation()->count());
        }
    }

    public function test_cancelled_case_rejects_late_confirmation_without_partial_state(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(CancelRegistrationCase::class)->execute($case->fresh(), $application->user, 'Learner cancelled before confirmation.');

        try {
            app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
            $this->fail('A cancelled Registration Case must reject late confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('proposal', $exception->errors());
        }

        $this->assertSame(Enrollment::OutcomeCancelled, $case->fresh()->canonical_outcome);
        $this->assertSame(0, $proposal->confirmation()->count());
    }

    public function test_manual_payment_and_approved_coverage_produce_a_mixed_exact_obligation_clearance(): void
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $case = app(StartRegistrationCase::class)->forReadyApplicant(
            $application,
            $term,
            $application->user,
            Enrollment::SelectionIndividuallyAdvised,
        );
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);

        $assessment = $this->recordIndividualAssessment(
            $case->fresh(),
            $accounting,
            'SYN-INDIVIDUAL-ASSESSMENT-001',
            [
                ['code' => 'TUITION', 'label' => 'Fixed tuition obligation', 'amount' => '600.00'],
                ['code' => 'LAB', 'label' => 'Fixed laboratory obligation', 'amount' => '400.00'],
            ],
        );
        $tuition = $assessment->obligations->firstWhere('code', 'TUITION');
        $laboratory = $assessment->obligations->firstWhere('code', 'LAB');
        app(RecordApprovedCoverage::class)->execute(
            $assessment->termAccount,
            $tuition,
            [
                'category' => ApprovedCoverage::CategoryScholarship,
                'safe_source_description' => 'Synthetic approved scholarship result',
                'amount' => '600.00',
                'authority_reference' => 'SYN-COVERAGE-001',
                'authority_date' => '2026-08-01',
                'effective_date' => '2026-08-01',
            ],
            $accounting,
        );
        $evidence = app(SubmitPaymentEvidence::class)->execute(
            $assessment->termAccount,
            $application->user,
            UploadedFile::fake()->image('manual-payment.jpg'),
            '400.00',
            'bank_transfer',
            now()->subMinute(),
            'SYN-MANUAL-PAYMENT-001',
        );

        $this->actingAs($application->user)
            ->get(route('finance.payment-evidence.download', $evidence))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $otherApplicant = User::factory()->create(['status' => User::StatusActive]);
        $otherApplicant->assignRole('applicant');
        $this->actingAs($otherApplicant)
            ->get(route('finance.payment-evidence.download', $evidence))
            ->assertForbidden();

        app(ReviewPaymentEvidence::class)->verify($evidence, $accounting, '400.00', 'SYN-INDEPENDENT-CHECK-001');

        $projection = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh());
        $this->assertSame('Cleared', $projection['state']);
        $this->assertSame('Mixed', $projection['satisfaction_basis']);
        $this->assertSame('600.00', $projection['coverage_applied']);
        $this->assertSame('400.00', $projection['payment_applied']);
    }

    public function test_official_adjustment_and_drop_create_successors_without_mutating_prior_cor(): void
    {
        [$official, $application, $term, $sourceSection, $timetable, $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $sourceCourse = $official->courseEnrollments()->where('is_current', true)->firstOrFail();
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 2,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 5,
        ]);
        $originalHash = $official->currentCorVersion->content_hash;
        $accounting = $this->staff(User::StaffRoleAccounting);
        $adjustmentProposal = $this->confirmedAdjustmentProposal(
            $official,
            $sourceCourse,
            $replacement,
            $registrar,
        );

        try {
            app(ApplyRegistrationAdjustment::class)->execute(
                $official,
                $adjustmentProposal,
                $registrar,
                'NoAdditionalCost',
                'SYN-ADJUSTMENT-WITHOUT-ACCOUNTING',
            );
            $this->fail('A Registrar label cannot replace attributable Accounting confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('financial_confirmation', $exception->errors());
            $this->assertTrue($sourceCourse->fresh()->is_current);
            $this->assertSame(1, $official->corVersions()->count());
        }

        $confirmation = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute(
            $official,
            $sourceCourse,
            $replacement,
            $accounting,
            'SYN-NO-COST-001',
        );

        app(ApplyRegistrationAdjustment::class)->execute(
            $official,
            $adjustmentProposal,
            $registrar,
            'NoAdditionalCost',
            'SYN-ADJUSTMENT-001',
            financialConfirmation: $confirmation,
        );
        $official->refresh();
        $successorCourse = $official->courseEnrollments()
            ->where('supersedes_course_enrollment_id', $sourceCourse->id)
            ->firstOrFail();

        $this->assertFalse($sourceCourse->fresh()->is_current);
        $this->assertSame($sourceCourse->id, $successorCourse->supersedes_course_enrollment_id);
        $this->assertSame(2, $official->corVersions()->count());
        $this->assertNotNull($confirmation->fresh()->consumed_at);
        $this->assertSame($originalHash, $official->corVersions()->where('version', 1)->value('content_hash'));
        $historicalVersion = $official->corVersions()->where('version', 1)->firstOrFail();
        $historicalOutput = app(BuildCorOutput::class)->forEnrollment(
            $official->fresh(),
            $application->user,
            BuildCorOutput::CopyStudent,
            requestedVersion: $historicalVersion,
        );
        $this->assertSame(1, $historicalOutput['summary']['cor_version']);
        $this->actingAs($application->user)
            ->get(route('cor.print', ['enrollment' => $official, 'version' => 1]))
            ->assertOk();
        $otherApplicant = User::factory()->create(['status' => User::StatusActive]);
        $otherApplicant->assignRole('applicant');
        $this->actingAs($otherApplicant)
            ->get(route('cor.print', ['enrollment' => $official, 'version' => 1]))
            ->assertForbidden();
        $this->actingAs($registrar)
            ->get(route('cor.print', ['enrollment' => $official, 'version' => 1]))
            ->assertOk();

        app(RecordCourseDrop::class)->execute(
            $official,
            $successorCourse,
            $registrar,
            'Learner requested an authorized drop.',
            'SYN-DROP-001',
        );

        $this->assertSame(CourseEnrollment::StatusDropped, $successorCourse->fresh()->status);
        $this->assertSame(1, $official->courseEnrollments()->where('is_current', true)->count());
        $this->assertSame(3, $official->corVersions()->count());
        $this->assertSame('Open', $official->termAccount->fresh()->state);
        $this->assertSame($application->user_id, $official->credential_user_id);
        $this->assertSame($term->id, $official->term_id);
    }

    public function test_all_four_authorized_individual_assessment_categories_preserve_versioned_authority(): void
    {
        [$official] = $this->officialEnrollment();
        $accounting = $this->staff(User::StaffRoleAccounting);
        $created = collect(Assessment::IndividualCategories)->map(function (string $category, int $index) use ($official, $accounting): Assessment {
            return $this->recordIndividualAssessment(
                $official->fresh(), $accounting, 'SYN-INDIVIDUAL-CATEGORY-'.($index + 1),
                [['code' => 'AUTHORIZED-'.$index, 'label' => $category.' fixed adjustment', 'amount' => '0.00']],
                $category,
            );
        });

        $this->assertSame(4, $created->count());
        $this->assertSame(Assessment::IndividualCategories, $created->pluck('reason_category')->all());
        $this->assertTrue($created->every(fn (Assessment $assessment): bool => $assessment->authority_date !== null && is_array($assessment->source_snapshot)));
        $this->assertSame(Assessment::StateActive, $created->last()->fresh()->state);
        $this->assertTrue($created->take(3)->every(fn (Assessment $assessment): bool => $assessment->fresh()->state === Assessment::StateSuperseded));
    }

    public function test_cost_increase_adjustment_requires_the_same_case_current_cleared_successor_assessment(): void
    {
        [$official, , , $sourceSection, $timetable, $registrar] = $this->officialEnrollment();
        $sourceCourse = $official->courseEnrollments()->where('is_current', true)->firstOrFail();
        $replacement = Section::factory()->for($sourceSection->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 2,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $replacement->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 5,
        ]);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $wrongAssessment = Assessment::factory()->create(['state' => Assessment::StateActive]);
        $adjustmentProposal = $this->confirmedAdjustmentProposal(
            $official,
            $sourceCourse,
            $replacement,
            $registrar,
            assisted: true,
        );

        try {
            app(ApplyRegistrationAdjustment::class)->execute(
                $official,
                $adjustmentProposal,
                $registrar,
                'Increase',
                'SYN-CROSS-CASE-ASSESSMENT',
                $wrongAssessment,
            );
            $this->fail('Another Registration Case assessment must not authorize this adjustment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
        }

        $successorAssessment = $this->recordIndividualAssessment(
            $official->fresh(),
            $accounting,
            'SYN-INCREASE-001',
            [['code' => 'INCREASE', 'label' => 'Authorized adjustment increase', 'amount' => '0.00']],
        );
        app(ApplyRegistrationAdjustment::class)->execute(
            $official->fresh(),
            $adjustmentProposal->fresh(),
            $registrar,
            'Increase',
            'SYN-INCREASE-ADJUSTMENT-001',
            $successorAssessment,
        );

        $this->assertFalse($sourceCourse->fresh()->is_current);
        $this->assertSame(2, $official->fresh()->corVersions()->count());
        $this->assertSame($successorAssessment->id, $official->fresh()->currentCorVersion->assessment_id);
    }

    public function test_learner_confirmed_adjustment_can_add_one_newly_eligible_course_atomically(): void
    {
        [$official, , , $sourceSection, $timetable, $registrar] = $this->officialEnrollment();
        $curriculum = $sourceSection->termOffering->curriculumEntry->curriculumVersion;
        $entry = CurriculumEntry::factory()->for($curriculum)->create();
        $offering = TermOffering::factory()
            ->for($official->term)
            ->for($entry)
            ->create(['state' => TermOffering::StateScheduled]);
        $addedSection = Section::factory()->for($offering, 'termOffering')->create([
            'state' => Section::StateOpen,
            'capacity' => 2,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $addedSection->id,
            'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
            'day_of_week' => 4,
        ]);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $adjustmentProposal = $this->confirmedAdjustmentProposal(
            $official,
            null,
            $addedSection,
            $registrar,
        );
        $confirmation = app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute(
            $official,
            null,
            $addedSection,
            $accounting,
            'SYN-NO-COST-ADD-001',
        );

        app(ApplyRegistrationAdjustment::class)->execute(
            $official,
            $adjustmentProposal,
            $registrar,
            RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost,
            'SYN-ADJUSTMENT-ADD-001',
            financialConfirmation: $confirmation,
        );

        $this->assertSame(2, $official->fresh()->courseEnrollments()->where('is_current', true)->count());
        $this->assertSame(2, $official->fresh()->corVersions()->count());
        $this->assertDatabaseHas('course_enrollments', [
            'enrollment_id' => $official->id,
            'section_id' => $addedSection->id,
            'is_current' => true,
        ]);
    }

    public function test_adjustment_window_and_exact_late_authority_are_enforced_without_boolean_bypass(): void
    {
        [$official, , $term, , , $registrar] = $this->officialEnrollment(withSecondCourse: true);
        $course = $official->courseEnrollments()->where('is_current', true)->firstOrFail();
        CalendarEvent::query()
            ->where('term_id', $term->id)
            ->where('process_key', CalendarEvent::ProcessAddDropAdjustment)
            ->update(['end_at' => now()->subMinute()]);

        try {
            app(RecordCourseDrop::class)->execute(
                $official,
                $course,
                $registrar,
                'Closed-window attempt.',
                'SYN-LATE-DROP-001',
            );
            $this->fail('A closed adjustment window must reject an ordinary Course Drop.');
        } catch (CalendarGateViolation $exception) {
            $this->assertSame('add_drop_adjustment_window', $exception->gate);
        }
        $this->assertTrue($course->fresh()->is_current);

        $lateAuthority = app(RecordRegistrationLateAuthority::class)->execute(
            $official,
            $course,
            null,
            $registrar,
            RegistrationLateAuthority::ActionCourseDrop,
            'Registrar Office',
            'SYN-LATE-DROP-001',
            CarbonImmutable::now(),
            'Approved exact-Term late Course Drop.',
            CarbonImmutable::now(),
            'Learner acknowledgement SYN-ACK-001',
            'Academic decision SYN-DECISION-001',
        );
        app(RecordCourseDrop::class)->execute(
            $official->fresh(),
            $course->fresh(),
            $registrar,
            'Approved exact-Term late Course Drop.',
            'SYN-LATE-DROP-001',
            $lateAuthority,
        );

        $this->assertNotNull($lateAuthority->fresh()->consumed_at);
        $this->assertSame(CourseEnrollment::StatusDropped, $course->fresh()->status);

        [$singleCourseEnrollment, , , , , $singleRegistrar] = $this->officialEnrollment();
        $lastCourse = $singleCourseEnrollment->courseEnrollments()->where('is_current', true)->firstOrFail();
        try {
            app(RecordCourseDrop::class)->execute(
                $singleCourseEnrollment,
                $lastCourse,
                $singleRegistrar,
                'This must be full withdrawal.',
                'SYN-LAST-COURSE-001',
            );
            $this->fail('Course Drop must not remove the final active course.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('drop', $exception->errors());
        }
        $this->assertTrue($lastCourse->fresh()->is_current);
    }

    public function test_source_impact_review_is_idempotent_and_never_changes_official_course_or_cor_history(): void
    {
        [$official, , , , , $registrar] = $this->officialEnrollment();
        $courseIds = $official->courseEnrollments()->pluck('id')->all();
        $corIds = $official->corVersions()->pluck('id')->all();

        $opened = app(RecordRegistrationSourceImpactReview::class)->open(
            $official,
            $registrar,
            RecordRegistrationSourceImpactReview::SourceAcademicResult,
            'grade-outcome-event:999',
            'Review a synthetic released academic result.',
        );
        $same = app(RecordRegistrationSourceImpactReview::class)->open(
            $official,
            $registrar,
            RecordRegistrationSourceImpactReview::SourceAcademicResult,
            'grade-outcome-event:999',
            'Review a synthetic released academic result.',
        );
        $resolved = app(RecordRegistrationSourceImpactReview::class)->resolve($official, $opened, $registrar, 'No registration change required.');

        $this->assertTrue($opened->is($same));
        $this->assertStringEndsWith('Resolved', $resolved->event_type);
        $this->assertSame($courseIds, $official->courseEnrollments()->pluck('id')->all());
        $this->assertSame($corIds, $official->corVersions()->pluck('id')->all());
        $this->assertSame(2, RegistrationCaseEvent::query()->where('authority_reference', 'grade-outcome-event:999')->count());
    }

    public function test_notification_failure_preserves_official_enrollment_and_authorized_resend_queues_once(): void
    {
        [$official, $application, , , , $registrar] = $this->officialEnrollment(invalidRecipientEmail: true);
        $event = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypeOfficialEnrollmentEmail)
            ->where('related_record_id', $official->id)
            ->firstOrFail();

        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame(OperationalEvent::StatusFailed, $event->status);
        Mail::assertNothingQueued();

        $application->user->update([
            'email' => 'applicant@example.test',
            'email_verified_at' => now(),
        ]);
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $component = Livewire::actingAs($application->user->fresh())
            ->test(StudentEnrollmentPage::class);
        $component
            ->assertOk()
            ->assertActionVisible('resendOfficialEnrollmentEmail')
            ->callAction('resendOfficialEnrollmentEmail')
            ->assertNotified('Enrollment email queued again');

        $this->assertSame(OperationalEvent::StatusPending, $event->fresh()->status);
        Mail::assertQueued(OfficialEnrollmentMail::class, 1);
    }

    /** @return array{AdmissionApplication, Term} */
    private function readyApplicant(): array
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        CalendarEvent::factory()->for($term)->create([
            'process_key' => CalendarEvent::ProcessEnrollment,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);
        CalendarEvent::factory()->for($term)->create([
            'process_key' => CalendarEvent::ProcessAddDropAdjustment,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);
        $cycle = AdmissionCycle::factory()->for($term)->create();
        $application = AdmissionApplication::factory()->for($cycle, 'admissionCycle')->create([
            'term_id' => $term->id,
            'application_state' => AdmissionApplication::StateAdmitted,
        ]);
        $application->user->update(['status' => User::StatusActive]);
        $application->user->assignRole('applicant');
        $requirementSet = AdmissionRequirementSet::factory()->published()->for($cycle)->create([
            'application_path' => $application->application_path,
        ]);
        $submission = ApplicationSubmissionVersion::factory()
            ->for($application, 'application')
            ->for($requirementSet, 'requirementSet')
            ->create(['submitted_by' => $application->user_id]);
        $application->update(['current_submission_version_id' => $submission->id]);
        AdmissionDecision::factory()->admitted()->for($application, 'application')->create();

        return [$application->refresh(), $term];
    }

    /** @return array{Section, PublishedTimetableVersion, CurriculumVersion} */
    private function publishedOffering(AdmissionApplication $application, Term $term): array
    {
        return $this->publishedOfferingForProgram($application->program, $term);
    }

    /** @return array{Section, PublishedTimetableVersion, CurriculumVersion} */
    private function publishedOfferingForProgram(
        Program $program,
        Term $term,
        ?CurriculumVersion $curriculum = null,
    ): array {
        $curriculum ??= CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'effective_entry_term_id' => $term->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->create();
        $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        $faculty = $this->staff(User::StaffRoleFaculty);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
        ]);

        return [$section, $timetable, $curriculum];
    }

    /** @return array{Enrollment, AdmissionApplication, Term, Section, PublishedTimetableVersion, User} */
    private function officialEnrollment(
        bool $invalidRecipientEmail = false,
        bool $withSecondCourse = false,
    ): array {
        [$application, $term] = $this->readyApplicant();
        [$section, $timetable] = $this->publishedOffering($application, $term);
        $sectionIds = [$section->id];
        if ($withSecondCourse) {
            $entry = CurriculumEntry::factory()->for($section->termOffering->curriculumEntry->curriculumVersion)->create();
            $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
            $secondSection = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen, 'capacity' => 2]);
            PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
                'section_id' => $secondSection->id,
                'faculty_user_id' => $this->staff(User::StaffRoleFaculty)->id,
                'day_of_week' => 3,
            ]);
            $sectionIds[] = $secondSection->id;
        }
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, $sectionIds, $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $plan = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'NO-PAYMENT', 'label' => 'Explicit no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic no-payment authority');
        app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
        if ($invalidRecipientEmail) {
            $application->user->update(['email' => 'invalid-recipient']);
        }
        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);

        return [$official, $application, $term, $section, $timetable, $registrar];
    }

    private function confirmedAdjustmentProposal(
        Enrollment $enrollment,
        ?CourseEnrollment $currentCourse,
        Section $replacementSection,
        User $registrar,
        bool $assisted = false,
    ): RegistrationProposalVersion {
        $sectionIds = $enrollment->courseEnrollments()
            ->where('is_current', true)
            ->where('status', CourseEnrollment::StatusActive)
            ->orderBy('id')
            ->pluck('section_id')
            ->map(fn (int $sectionId): int => $currentCourse !== null && $sectionId === (int) $currentCourse->section_id
                ? (int) $replacementSection->id
                : $sectionId)
            ->push((int) $replacementSection->id)
            ->unique()
            ->values()
            ->all();

        $proposal = app(PrepareRegistrationProposal::class)->execute(
            $enrollment->fresh(),
            $registrar,
            $sectionIds,
            $enrollment->fresh()->lock_version,
            RegistrationProposalVersion::PurposeAdjustment,
        );
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        $learner = User::query()->findOrFail($enrollment->credential_user_id);

        return app(ConfirmRegistrationProposal::class)->execute(
            $proposal->fresh(),
            $learner,
            assistedBy: $assisted ? $registrar : null,
            assistedEvidenceReference: $assisted ? 'SYN-ASSISTED-ADJUSTMENT-CONFIRMATION' : null,
        );
    }

    /** @return array{Enrollment, AdmissionApplication, Section, User} */
    private function caseReadyForFinalization(): array
    {
        [$application, $term] = $this->readyApplicant();
        [$section] = $this->publishedOffering($application, $term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $case = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $application->user);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
        app(ConfirmRegistrationProposal::class)->execute($proposal->fresh(), $application->user);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $plan = $this->createFeePlanDraft(
            $application->program,
            $term,
            [['code' => 'NO-PAYMENT', 'label' => 'Explicit no-payment authority', 'amount' => '0.00']],
            $accounting,
        );
        $this->publishFeePlan($plan, $accounting, 'Synthetic exact-Term authority');
        app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);

        return [$case->fresh(), $application, $section, $registrar];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    /** @param list<array{code:string,label:string,amount:string}> $charges */
    private function createFeePlanDraft(Program $program, Term $term, array $charges, User $accounting): FeePlan
    {
        return app(CreateFeePlanDraft::class)->execute(
            $program,
            $term,
            $charges,
            $accounting,
            obligations: $this->feePlanObligations($charges, $term),
        );
    }

    private function publishFeePlan(FeePlan $plan, User $accounting, string $authorityReference): FeePlan
    {
        return app(PublishFeePlan::class)->execute(
            $plan,
            $accounting,
            $authorityReference,
            CarbonImmutable::parse('2026-08-01', config('app.timezone')),
        );
    }

    /** @param list<array{code:string,label:string,amount:string}> $charges */
    private function recordIndividualAssessment(Enrollment $enrollment, User $accounting, string $authorityReference, array $charges, string $category = Assessment::CategoryIndividuallyAdvised): Assessment
    {
        return app(RecordAuthorizedIndividualAssessment::class)->execute(
            $enrollment,
            $accounting,
            $category,
            $authorityReference,
            CarbonImmutable::parse('2026-08-01', config('app.timezone')),
            $charges,
            $this->feePlanObligations($charges, $enrollment->term),
        );
    }

    /** @param list<array{code:string,label:string,amount:string}> $charges */
    private function updateFeePlanDraft(FeePlan $plan, array $charges, User $accounting): FeePlan
    {
        return app(UpdateFeePlanDraft::class)->execute(
            $plan,
            $charges,
            $this->feePlanObligations($charges, $plan->term),
            $accounting,
        );
    }

    /**
     * @param  list<array{code:string,label:string,amount:string}>  $charges
     * @return list<array{code:string,label:string,purpose:string,amount:string,due_at:string,required_for_enrollment:bool}>
     */
    private function feePlanObligations(array $charges, Term $term): array
    {
        return collect($charges)->map(fn (array $charge): array => [
            'code' => $charge['code'],
            'label' => $charge['label'],
            'purpose' => 'Enrollment',
            'amount' => $charge['amount'],
            'due_at' => now()->subMinute()->toDateTimeString(),
            'required_for_enrollment' => true,
        ])->all();
    }
}
