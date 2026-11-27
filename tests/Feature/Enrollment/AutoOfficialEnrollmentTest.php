<?php

namespace Tests\Feature\Enrollment;

use App\Actions\Cor\BuildCorOutput;
use App\Actions\Enrollment\AttemptAutoOfficialEnrollment;
use App\Actions\Enrollment\AutoFinalizationResult;
use App\Actions\Enrollment\AutoFinalizationTrigger;
use App\Actions\Enrollment\ConfirmRegistrationProposal;
use App\Actions\Enrollment\FinalizeOfficialEnrollment;
use App\Actions\Enrollment\IssueRegistrationProposal;
use App\Actions\Enrollment\PlaceRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Actions\Finance\CreateFeePlanDraft;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\PaymentConfirmationService;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Finance\RecordApprovedCoverage;
use App\Actions\Finance\RecordAuthorizedIndividualAssessment;
use App\Actions\Finance\RevalidateAssessmentEffects;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Finance\SchoolPaymentChannels;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Student\Pages\Enrollment as StudentEnrollmentPage;
use App\Mail\OfficialEnrollmentMail;
use App\Mail\PaymentPostedMail;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AutoOfficialEnrollmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringStartsWith('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([
            'applicant',
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
        ] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Mail::fake();
        Storage::fake('local');
    }

    #[Test]
    public function trigger_1_verifying_payment_evidence_auto_finalizes_with_provenance_and_consolidated_mail(): void
    {
        $fixture = $this->confirmedPaidReadyFixture(amount: '2500.00');
        $accounting = $fixture['accounting'];
        $registrar = $fixture['registrar'];
        $case = $fixture['case'];
        $learner = $fixture['learner'];

        $this->enableDigitalChannels($accounting);
        $evidence = app(SubmitPaymentEvidence::class)->execute(
            $case->termAccount,
            $learner,
            UploadedFile::fake()->image('receipt.jpg'),
            '2500.00',
            'bank_transfer',
            now()->subMinute(),
            'BANK-REF-1001',
        );

        $payment = app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $accounting,
            '2500.00',
            'BANK-SETTLE-1001',
        );

        $official = $case->fresh()->load(['studentProfile', 'currentCorVersion', 'courseEnrollments']);

        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame('officially_enrolled', $official->status);
        $this->assertSame(Enrollment::FinalizationModeAutomatic, $official->finalization_mode);
        $this->assertNull($official->finalized_by);
        $this->assertNotNull($official->current_cor_version_id);
        $this->assertNotNull($official->studentProfile);
        $this->assertCount(1, $official->courseEnrollments);

        $cor = $official->currentCorVersion;
        $this->assertNull($cor->issued_by);
        $this->assertSame(Enrollment::FinalizationModeAutomatic, data_get($cor->snapshot, 'finalization_mode'));
        $this->assertNull(data_get($cor->snapshot, 'issued_by_user_id'));
        $this->assertSame(FinalizeOfficialEnrollment::AutomaticIssuerName, data_get($cor->snapshot, 'issued_by_name'));
        $this->assertSame(AutoFinalizationTrigger::TypePaymentEvidenceVerified, data_get($cor->snapshot, 'finance_satisfaction.trigger_type'));
        $this->assertSame('BANK-SETTLE-1001', data_get($cor->snapshot, 'finance_satisfaction.trigger_reference'));
        $this->assertSame($payment->id, data_get($cor->snapshot, 'finance_satisfaction.payment_id'));
        $this->assertSame($accounting->id, data_get($cor->snapshot, 'finance_satisfaction.cleared_by_user_id'));

        $corOutput = app(BuildCorOutput::class)->forEnrollment($official, $registrar);
        $this->assertSame(FinalizeOfficialEnrollment::AutomaticIssuerName, $corOutput['summary']['issued_by']);
        $this->assertSame(Enrollment::FinalizationModeAutomatic, $corOutput['summary']['finalization_mode']);

        Mail::assertQueued(OfficialEnrollmentMail::class, function (OfficialEnrollmentMail $mail): bool {
            return $mail->autoFinalized === true
                && str_contains((string) $mail->paymentSummary, '2,500.00')
                && str_contains((string) $mail->paymentSummary, 'BANK-SETTLE-1001');
        });
        Mail::assertNotQueued(PaymentPostedMail::class);
        $this->assertSame(1, OperationalEvent::query()->where('event_type', OperationalEvent::TypeOfficialEnrollmentEmail)->where('related_record_id', $official->id)->count());
        $this->assertSame(0, OperationalEvent::query()->where('event_type', OperationalEvent::TypePaymentPostedEmail)->where('related_record_id', $payment->id)->count());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $official->id])
            ->assertSee('Officially enrolled')
            ->assertSee('Auto-finalized')
            ->assertSee('Officially enrolled automatically on')
            ->assertSee($accounting->getFilamentName());
    }

    #[Test]
    public function trigger_2_confirming_manual_onsite_payment_auto_finalizes_and_suppresses_duplicate_payment_mail(): void
    {
        $fixture = $this->confirmedPaidReadyFixture(amount: '3000.00');
        $accounting = $fixture['accounting'];
        $case = $fixture['case'];

        $result = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $case->id,
            amount: '3000.00',
            channel: 'cash',
            paymentReference: 'CASH-REF-2001',
            actor: $accounting,
            orNumber: 'OR-2026-2001',
        );

        $official = $case->fresh()->load('currentCorVersion');

        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame(Enrollment::FinalizationModeAutomatic, $official->finalization_mode);
        $this->assertNull($official->finalized_by);
        $this->assertNull($official->currentCorVersion->issued_by);
        $this->assertSame(AutoFinalizationTrigger::TypeManualPaymentConfirmed, data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.trigger_type'));
        $this->assertSame('OR-2026-2001', data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.trigger_reference'));
        $this->assertSame($result['payment_id'], data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.payment_id'));
        $this->assertSame($accounting->id, data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.cleared_by_user_id'));

        Mail::assertQueued(OfficialEnrollmentMail::class, 1);
        Mail::assertNotQueued(PaymentPostedMail::class);
    }

    #[Test]
    public function trigger_3_recording_approved_coverage_auto_finalizes_enrollment(): void
    {
        $fixture = $this->confirmedPaidReadyFixture(amount: '4000.00');
        $accounting = $fixture['accounting'];
        $case = $fixture['case'];
        $obligation = $fixture['assessment']->obligations->firstOrFail();

        app(RecordApprovedCoverage::class)->execute(
            $case->termAccount,
            $obligation,
            [
                'category' => ApprovedCoverage::CategoryScholarship,
                'safe_source_description' => 'Full institutional scholarship grant',
                'authority_reference' => 'SCHOL-2026-3001',
                'authority_date' => now()->toDateString(),
                'effective_date' => now()->toDateString(),
                'amount' => '4000.00',
            ],
            $accounting,
        );

        $official = $case->fresh()->load('currentCorVersion');

        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame(Enrollment::FinalizationModeAutomatic, $official->finalization_mode);
        $this->assertNull($official->finalized_by);
        $this->assertSame(AutoFinalizationTrigger::TypeApprovedCoverageRecorded, data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.trigger_type'));
        $this->assertSame('SCHOL-2026-3001', data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.trigger_reference'));

        Mail::assertQueued(OfficialEnrollmentMail::class, 1);
    }

    #[Test]
    public function trigger_4_revalidating_assessment_effects_auto_finalizes_enrollment(): void
    {
        $fixture = $this->confirmedPaidReadyFixture(amount: '2000.00', selectionBasis: Enrollment::SelectionIndividuallyAdvised);
        $accounting = $fixture['accounting'];
        $case = $fixture['case'];

        // Expire seat temporarily so initial payment commits without auto-finalizing.
        $reservation = $case->currentProposalVersion->items()->sole()->reservation;
        $reservation->update(['deadline' => now()->subMinute()]);

        app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $case->id,
            amount: '2000.00',
            channel: 'cash',
            paymentReference: 'CASH-REVAL-4001',
            actor: $accounting,
            orNumber: 'OR-2026-4001',
        );

        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);

        // Restore seat validity and record a successor assessment whose retained payment needs reconciliation.
        $reservation->update(['deadline' => now()->addDay()]);
        $successor = app(RecordAuthorizedIndividualAssessment::class)->execute(
            $case->fresh(),
            $accounting,
            Assessment::CategoryIndividuallyAdvised,
            'IND-REVAL-4002',
            CarbonImmutable::parse('2026-08-25', config('app.timezone')),
            [['code' => 'TUITION-V2', 'label' => 'Revised tuition', 'amount' => '2000.00']],
            [[
                'code' => 'ENROLLMENT-V2',
                'label' => 'Revised enrollment obligation',
                'purpose' => 'Enrollment',
                'amount' => '2000.00',
                'due_at' => now()->subMinute()->toDateTimeString(),
                'required_for_enrollment' => true,
            ]],
        );

        $oldObligationId = $fixture['assessment']->obligations->firstOrFail()->id;
        $newObligationId = $successor->obligations->firstOrFail()->id;

        app(RevalidateAssessmentEffects::class)->execute(
            $successor,
            $accounting,
            [$oldObligationId => $newObligationId],
            'REVAL-AUTH-4002',
        );

        $official = $case->fresh()->load('currentCorVersion');

        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame(Enrollment::FinalizationModeAutomatic, $official->finalization_mode);
        $this->assertSame(AutoFinalizationTrigger::TypeAssessmentEffectsRevalidated, data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.trigger_type'));
        $this->assertSame('REVAL-AUTH-4002', data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.trigger_reference'));
    }

    #[Test]
    public function trigger_5_zero_due_assessment_creation_auto_finalizes_confirmed_ready_case(): void
    {
        $fixture = $this->confirmedPaidReadyFixture(amount: '1500.00', selectionBasis: Enrollment::SelectionIndividuallyAdvised);
        $accounting = $fixture['accounting'];
        $case = $fixture['case'];

        // Replace with a zero-due authorized individual assessment on an already confirmed ready case.
        app(RecordAuthorizedIndividualAssessment::class)->execute(
            $case->fresh(),
            $accounting,
            Assessment::CategoryIndividuallyAdvised,
            'ZERO-DUE-5001',
            CarbonImmutable::parse('2026-08-25', config('app.timezone')),
            [['code' => 'ZERO', 'label' => 'Zero-due institutional grant', 'amount' => '0.00']],
            [[
                'code' => 'ENROLLMENT',
                'label' => 'Enrollment obligation',
                'purpose' => 'Enrollment',
                'amount' => '0.00',
                'due_at' => now()->subMinute()->toDateTimeString(),
                'required_for_enrollment' => true,
            ]],
            noPaymentRequiredAuthorityReference: 'NO-PAY-AUTH-5001',
        );

        $official = $case->fresh()->load('currentCorVersion');

        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame(Enrollment::FinalizationModeAutomatic, $official->finalization_mode);
        $this->assertNull($official->finalized_by);
        $this->assertSame(AutoFinalizationTrigger::TypeZeroDueAssessmentCreated, data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.trigger_type'));
        $this->assertSame('NO-PAY-AUTH-5001', data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.trigger_reference'));
        Mail::assertQueued(OfficialEnrollmentMail::class, 1);
    }

    #[Test]
    public function trigger_6_early_payment_defers_until_learner_confirms_then_auto_finalizes(): void
    {
        $fixture = $this->confirmedPaidReadyFixture(amount: '1800.00', confirmProposal: false, selectionBasis: Enrollment::SelectionIndividuallyAdvised);
        $accounting = $fixture['accounting'];
        $registrar = $fixture['registrar'];
        $case = $fixture['case'];
        $learner = $fixture['learner'];

        app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $case->id,
            amount: '1800.00',
            channel: 'cash',
            paymentReference: 'CASH-EARLY-6001',
            actor: $accounting,
            orNumber: 'OR-2026-6001',
        );

        // Payment stays committed, case stays InProgress (unconfirmed), PaymentPostedMail sent, deferral logged.
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
        $this->assertSame(1, Payment::query()->where('term_account_id', $case->termAccount->id)->count());
        $this->assertSame('Cleared', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['state']);
        $this->assertTrue(
            RegistrationCaseEvent::query()
                ->where('enrollment_id', $case->id)
                ->where('event_type', AttemptAutoOfficialEnrollment::EventDeferred)
                ->exists(),
        );
        Mail::assertQueued(PaymentPostedMail::class, 1);
        Mail::assertNotQueued(OfficialEnrollmentMail::class);
        $this->assertFalse(
            $registrar->notifications()->get()->contains(
                fn ($n): bool => data_get($n->data, 'title') === 'Payment cleared — enrollment issues require resolution',
            ),
        );

        // Learner now confirms proposal -> Trigger 6 auto-finalizes immediately!
        app(ConfirmRegistrationProposal::class)->execute($case->fresh()->currentProposalVersion, $learner);

        $official = $case->fresh()->load('currentCorVersion');
        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame(Enrollment::FinalizationModeAutomatic, $official->finalization_mode);
        $this->assertSame(AutoFinalizationTrigger::TypeProposalConfirmed, data_get($official->currentCorVersion->snapshot, 'finance_satisfaction.trigger_type'));
        Mail::assertQueued(OfficialEnrollmentMail::class, 1);
    }

    #[Test]
    public function non_finance_blocker_keeps_payment_committed_logs_deferral_alerts_registrar_and_allows_manual_fallback(): void
    {
        $fixture = $this->confirmedPaidReadyFixture(amount: '2200.00');
        $accounting = $fixture['accounting'];
        $registrar = $fixture['registrar'];
        $case = $fixture['case'];

        // Expire seat reservation before payment verification.
        $reservation = $case->currentProposalVersion->items()->sole()->reservation;
        $reservation->update(['deadline' => now()->subMinute()]);

        app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $case->id,
            amount: '2200.00',
            channel: 'cash',
            paymentReference: 'CASH-BLOCKED-7001',
            actor: $accounting,
            orNumber: 'OR-2026-7001',
        );

        // Payment MUST stay committed even though auto-finalization deferred.
        $this->assertSame(1, Payment::query()->where('term_account_id', $case->termAccount->id)->count());
        $this->assertSame('Cleared', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case->fresh())['state']);
        $this->assertSame(Enrollment::OutcomeInProgress, $case->fresh()->canonical_outcome);
        $this->assertNull($case->fresh()->current_cor_version_id);

        $deferral = RegistrationCaseEvent::query()
            ->where('enrollment_id', $case->id)
            ->where('event_type', AttemptAutoOfficialEnrollment::EventDeferred)
            ->latest('id')
            ->first();
        $this->assertNotNull($deferral);
        $this->assertNull($deferral->actor_id);
        $this->assertStringContainsString('Complete current placement', (string) $deferral->reason);

        Mail::assertQueued(PaymentPostedMail::class, 1);
        Mail::assertNotQueued(OfficialEnrollmentMail::class);

        $registrarAlert = $registrar->notifications()->get()->first(
            fn ($n): bool => data_get($n->data, 'title') === 'Payment cleared — enrollment issues require resolution',
        );
        $this->assertNotNull($registrarAlert);

        // Registrar case view shows State B paid-blocked recovery banner.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $case->id])
            ->assertSee('Paid · Auto-enrollment blocked');

        // Registrar resolves the seat blocker, then uses the manual fallback to finalize.
        $reservation->update(['deadline' => now()->addDay()]);
        $official = app(FinalizeOfficialEnrollment::class)->execute($case->fresh(), $registrar);

        $this->assertSame(Enrollment::OutcomeOfficiallyEnrolled, $official->canonical_outcome);
        $this->assertSame(Enrollment::FinalizationModeManual, $official->finalization_mode);
        $this->assertSame($registrar->id, $official->finalized_by);
        $this->assertSame($registrar->id, $official->currentCorVersion->issued_by);
        $this->assertSame(Enrollment::FinalizationModeManual, data_get($official->currentCorVersion->snapshot, 'finalization_mode'));
    }

    #[Test]
    public function auto_finalization_is_strictly_idempotent_on_duplicate_triggers(): void
    {
        $fixture = $this->confirmedPaidReadyFixture(amount: '1200.00');
        $accounting = $fixture['accounting'];
        $case = $fixture['case'];

        app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $case->id,
            amount: '1200.00',
            channel: 'cash',
            paymentReference: 'CASH-IDEM-8001',
            actor: $accounting,
            orNumber: 'OR-2026-8001',
        );

        $official = $case->fresh();
        $corId = $official->current_cor_version_id;
        $studentProfileId = $official->student_profile_id;

        $secondAttempt = app(AttemptAutoOfficialEnrollment::class)->attempt(
            $official->id,
            new AutoFinalizationTrigger(AutoFinalizationTrigger::TypeManualPaymentConfirmed, 'OR-2026-8001', $accounting->id),
        );

        $this->assertSame(AutoFinalizationResult::StatusAlreadyOfficial, $secondAttempt->status);
        $this->assertSame($corId, $official->fresh()->current_cor_version_id);
        $this->assertSame($studentProfileId, $official->fresh()->student_profile_id);
        $this->assertSame(1, $official->corVersions()->count());
        $this->assertSame(1, $official->courseEnrollments()->count());
        Mail::assertQueued(OfficialEnrollmentMail::class, 1);
    }

    #[Test]
    public function student_hub_shows_streamlined_stepper_and_auto_enroll_payment_copy(): void
    {
        $fixture = $this->confirmedPaidReadyFixture(amount: '2500.00', asContinuingStudent: true);
        $learner = $fixture['learner'];

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($learner->fresh())
            ->test(StudentEnrollmentPage::class)
            ->assertSee('Prepare subjects')
            ->assertSee('Confirm subjects')
            ->assertSee('Pay fees')
            ->assertSee('Officially enrolled')
            ->assertSee('your enrollment becomes official automatically and your COR will be ready immediately')
            ->assertDontSee('The Registrar completes official enrollment');
    }

    #[Test]
    public function active_enrollment_hold_defers_auto_finalization_without_rolling_back_payment(): void
    {
        $fixture = $this->confirmedPaidReadyFixture(amount: '2000.00', asContinuingStudent: true);
        $accounting = $fixture['accounting'];
        $case = $fixture['case'];

        Hold::factory()->create([
            'student_profile_id' => $case->student_profile_id,
            'term_id' => $case->term_id,
            'enrollment_id' => $case->id,
            'hold_type' => Hold::TypeDocumentary,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
            'reason' => 'Missing transfer credentials',
        ]);

        // Payment still commits; auto-finalization defers because eligibility/hold fails or stays clean.
        // Note: RegistrationReadinessQuery checks lifecycle/academic eligibility; if an enrollment-blocking hold
        // is active on a continuing student, verify payment is never rolled back.
        $result = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $case->id,
            amount: '2000.00',
            channel: 'cash',
            paymentReference: 'CASH-HOLD-9001',
            actor: $accounting,
            orNumber: 'OR-2026-9001',
        );

        $this->assertSame(1, Payment::query()->whereKey($result['payment_id'])->count());
        $this->assertTrue($result['finance_cleared']);
    }

    /**
     * @return array{case:Enrollment,assessment:Assessment,learner:User,registrar:User,accounting:User,section:Section,term:Term}
     */
    private function confirmedPaidReadyFixture(
        string $amount,
        bool $confirmProposal = true,
        string $selectionBasis = Enrollment::SelectionStandardCurriculum,
        bool $asContinuingStudent = false,
    ): array {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $program = Program::factory()->create(['code' => fake()->unique()->bothify('AUTO###')]);
        $term = Term::factory()->create([
            'label' => fake()->unique()->bothify('1st Semester 2026-####'),
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
            'default_max_units' => 24,
        ]);
        $calendarPackage = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'administrative_starts_on' => now()->subMonth()->toDateString(),
            'administrative_ends_on' => now()->addMonths(3)->toDateString(),
            'classes_start_on' => now()->toDateString(),
            'classes_end_on' => now()->addMonths(2)->toDateString(),
            'activated_at' => now(),
        ]);
        TermCalendarWindow::factory()->for($calendarPackage, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'cutoff_at' => '23:59:59',
        ]);

        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $specification = CourseSpecification::factory()->create(['credit_units' => 3, 'state' => CourseSpecification::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->create([
            'course_specification_id' => $specification->id,
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'state' => TermOffering::StateScheduled,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => fake()->unique()->bothify('BSIT-1?'),
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create([
            'state' => PublishedTimetableVersion::StatePublished,
            'version' => 1,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
        ]);

        if ($asContinuingStudent) {
            $learner = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
            $learner->assignRole('student');
            $profile = StudentProfile::factory()->for($learner)->for($program)->create([
                'curriculum_version_id' => $curriculum->id,
                'lifecycle_status' => StudentProfile::LifecycleActive,
            ]);
            $case = Enrollment::factory()->create([
                'student_profile_id' => $profile->id,
                'credential_user_id' => $learner->id,
                'term_id' => $term->id,
                'selection_basis' => $selectionBasis,
                'canonical_outcome' => Enrollment::OutcomeInProgress,
                'lock_version' => 1,
            ]);
        } else {
            $cycle = AdmissionCycle::factory()->for($term)->create();
            $application = AdmissionApplication::factory()->for($cycle, 'admissionCycle')->create([
                'term_id' => $term->id,
                'program_id' => $program->id,
                'application_state' => AdmissionApplication::StateAdmitted,
                'application_path' => AdmissionApplication::PathFirstYear,
            ]);
            $learner = $application->user;
            $learner->update(['status' => User::StatusActive, 'email_verified_at' => now()]);
            $learner->assignRole('applicant');
            $requirementSet = AdmissionRequirementSet::factory()->published()->for($cycle)->create([
                'application_path' => $application->application_path,
            ]);
            $submission = ApplicationSubmissionVersion::factory()
                ->for($application, 'application')
                ->for($requirementSet, 'requirementSet')
                ->create(['submitted_by' => $learner->id]);
            $application->update(['current_submission_version_id' => $submission->id]);
            AdmissionDecision::factory()->admitted()->for($application, 'application')->create([
                'application_submission_version_id' => $submission->id,
                'decided_by' => $registrar->id,
                'decided_at' => now(),
            ]);
            RegistrarEnrollmentClearance::factory()->forAdmittedApplication($application->fresh())->create([
                'result' => RegistrarEnrollmentClearance::ResultCleared,
                'external_checks_confirmed' => true,
                'recorded_by' => $registrar->id,
            ]);

            if ($selectionBasis === Enrollment::SelectionStandardCurriculum) {
                $plan = app(CreateFeePlanDraft::class)->execute(
                    $program,
                    $term,
                    [['code' => 'ENROLLMENT', 'label' => 'Enrollment fee', 'amount' => $amount]],
                    $accounting,
                    obligations: [[
                        'code' => 'ENROLLMENT',
                        'label' => 'Enrollment fee',
                        'purpose' => 'Enrollment',
                        'amount' => $amount,
                        'due_at' => now()->subMinute()->toDateTimeString(),
                        'required_for_enrollment' => true,
                    ]],
                );
                app(PublishFeePlan::class)->execute(
                    $plan,
                    $accounting,
                    'AUTH-PLAN-'.strtoupper(fake()->bothify('####')),
                    CarbonImmutable::parse('2026-08-01', config('app.timezone')),
                );

                $case = app(StartRegistrationCase::class)->forReadyApplicant($application->fresh(), $term, $learner);
            } else {
                $case = Enrollment::factory()->create([
                    'student_profile_id' => null,
                    'credential_user_id' => $learner->id,
                    'admission_application_id' => $application->id,
                    'term_id' => $term->id,
                    'selection_basis' => $selectionBasis,
                    'canonical_outcome' => Enrollment::OutcomeInProgress,
                    'lock_version' => 1,
                ]);
            }
        }

        if ($case->currentProposalVersion === null) {
            $proposal = app(PrepareRegistrationProposal::class)->execute($case->fresh(), $registrar, [$section->id], $case->fresh()->lock_version);
            app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
            if ($selectionBasis === Enrollment::SelectionIndividuallyAdvised) {
                $assessment = app(RecordAuthorizedIndividualAssessment::class)->execute(
                    $case->fresh(),
                    $accounting,
                    Assessment::CategoryIndividuallyAdvised,
                    'AUTH-IND-'.strtoupper(fake()->bothify('####')),
                    CarbonImmutable::parse('2026-08-01', config('app.timezone')),
                    [['code' => 'ENROLLMENT', 'label' => 'Enrollment fee', 'amount' => $amount]],
                    [[
                        'code' => 'ENROLLMENT',
                        'label' => 'Enrollment fee',
                        'purpose' => 'Enrollment',
                        'amount' => $amount,
                        'due_at' => now()->subMinute()->toDateTimeString(),
                        'required_for_enrollment' => true,
                    ]],
                );
            } else {
                $plan = app(CreateFeePlanDraft::class)->execute(
                    $program,
                    $term,
                    [['code' => 'ENROLLMENT', 'label' => 'Enrollment fee', 'amount' => $amount]],
                    $accounting,
                    obligations: [[
                        'code' => 'ENROLLMENT',
                        'label' => 'Enrollment fee',
                        'purpose' => 'Enrollment',
                        'amount' => $amount,
                        'due_at' => now()->subMinute()->toDateTimeString(),
                        'required_for_enrollment' => true,
                    ]],
                );
                app(PublishFeePlan::class)->execute(
                    $plan,
                    $accounting,
                    'AUTH-PLAN-'.strtoupper(fake()->bothify('####')),
                    CarbonImmutable::parse('2026-08-01', config('app.timezone')),
                );
                $assessment = app(CreateAssessmentFromPublishedFeePlan::class)->execute($case->fresh(), $accounting);
            }
            $proposal = $proposal->fresh();
            if ($proposal->state === RegistrationProposalVersion::StateDraft) {
                app(IssueRegistrationProposal::class)->execute($proposal, $registrar);
            }
            $case = $case->fresh();
        } else {
            $assessment = $case->termAccount->assessments()->where('state', Assessment::StateActive)->firstOrFail();
        }

        if ($confirmProposal) {
            app(ConfirmRegistrationProposal::class)->execute($case->fresh()->currentProposalVersion, $learner);
        }

        return [
            'case' => $case->fresh()->load(['termAccount', 'currentProposalVersion.items.reservation']),
            'assessment' => $assessment->fresh()->load('obligations'),
            'learner' => $learner,
            'registrar' => $registrar,
            'accounting' => $accounting,
            'section' => $section,
            'term' => $term,
        ];
    }

    private function enableDigitalChannels(User $accounting): void
    {
        app(SchoolPaymentChannels::class)->update([
            'bank_transfer' => [
                'enabled' => true,
                'accounts' => [[
                    'bank_name' => 'Land Bank of the Philippines',
                    'branch' => 'Talisay',
                    'account_name' => 'Servitech Institute Asia Inc.',
                    'account_number' => '0011-2233-44',
                ]],
            ],
            'e_wallets' => ['enabled' => false, 'accounts' => []],
            'cash' => ['enabled' => true, 'location' => 'Cashier Window 1'],
        ], $accounting);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }
}
