<?php

namespace Tests\Feature;

use App\Actions\Finance\EnrollmentFinanceClearanceService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Actions\Finance\PromissoryNoteLifecycleService;
use App\Models\AdmissionCapacityPlan;
use App\Models\AdmissionCapacityReservation;
use App\Models\AdmissionOffering;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantDocumentRequirement;
use App\Models\ApplicantIntake;
use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\DocumentRequirementItem;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use App\Models\FacultySubjectEligibility;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PromissoryNote;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaymentConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_accounting_authorized_users_can_confirm_manual_payments(): void
    {
        [$enrollment] = $this->paymentContext();
        $unauthorizedUser = User::factory()->create();

        try {
            app(PaymentConfirmationService::class)->confirmManualPayment(
                enrollmentId: $enrollment->id,
                amount: '100.00',
                channel: 'cash',
                paymentReference: 'OR-UNAUTHORIZED',
                actor: $unauthorizedUser,
            );

            $this->fail('Unauthorized payment confirmation should throw.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only Accounting/Cashier can confirm payments.', $exception->getMessage());
        }

        $this->assertDatabaseCount(Payment::class, 0);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'assessment')->count());
    }

    public function test_manual_payment_posts_one_payment_one_negative_ledger_credit_and_audit_evidence(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext();
        $confirmedAt = CarbonImmutable::now(config('app.timezone'))->subDay()->startOfMinute();

        $summary = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $enrollment->id,
            amount: '100.00',
            channel: 'cash',
            paymentReference: '  OR-100  ',
            actor: $accounting,
            confirmedAt: $confirmedAt,
        );

        $payment = Payment::query()->findOrFail($summary['payment_id']);
        $ledgerEntry = LedgerEntry::query()->findOrFail($summary['ledger_entry_id']);

        $this->assertSame('OR-100', $payment->payment_reference);
        $this->assertSame('cash', $payment->channel);
        $this->assertSame('100.00', $payment->amount);
        $this->assertSame('confirmed', $payment->status);
        $this->assertTrue($payment->confirmed_at->equalTo($confirmedAt));
        $this->assertSame($ledgerEntry->id, $payment->ledger_entry_id);
        $this->assertSame('payment', $ledgerEntry->entry_type);
        $this->assertSame('payment', $ledgerEntry->reference_type);
        $this->assertSame($payment->id, $ledgerEntry->reference_id);
        $this->assertSame('-100.00', $ledgerEntry->amount);
        $this->assertSame('900.00', $ledgerEntry->running_balance);
        $this->assertSame('900.00', $summary['current_balance']);
        $this->assertSame('200.00', $summary['minimum_required_payment']);
        $this->assertSame('100.00', $summary['total_confirmed_payments']);
        $this->assertFalse($summary['finance_cleared']);
        $this->assertSame('900.00', $studentProfile->fresh()->current_balance);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Enrollment::class,
            'subject_id' => $enrollment->id,
            'event' => 'payment_confirmed',
        ]);
    }

    public function test_duplicate_manual_reference_is_rejected_after_normalization_without_double_posting(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext();
        $service = app(PaymentConfirmationService::class);

        $service->confirmManualPayment(
            enrollmentId: $enrollment->id,
            amount: '50.00',
            channel: 'cash',
            paymentReference: 'OR-DUPLICATE',
            actor: $accounting,
        );

        try {
            $service->confirmManualPayment(
                enrollmentId: $enrollment->id,
                amount: '25.00',
                channel: 'cash',
                paymentReference: '  OR-DUPLICATE  ',
                actor: $accounting,
            );

            $this->fail('A duplicate normalized reference should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payment reference already exists.', $exception->getMessage());
        }

        $this->assertDatabaseCount(Payment::class, 1);
        $this->assertSame(1, LedgerEntry::query()->where('entry_type', 'payment')->count());
        $this->assertSame('950.00', $studentProfile->fresh()->current_balance);
    }

    public function test_manual_confirmation_requires_reference_supported_channel_and_prior_assessment(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext();
        $service = app(PaymentConfirmationService::class);

        foreach ([
            ['channel' => 'cash', 'reference' => '   ', 'message' => 'Payment reference is required.'],
            ['channel' => 'paymongo_reconciled', 'reference' => 'PAYMONGO-1', 'message' => 'Unsupported manual payment channel.'],
        ] as $case) {
            $caughtException = null;

            try {
                $service->confirmManualPayment(
                    enrollmentId: $enrollment->id,
                    amount: '50.00',
                    channel: $case['channel'],
                    paymentReference: $case['reference'],
                    actor: $accounting,
                );
            } catch (RuntimeException $exception) {
                $caughtException = $exception;
            }

            $this->assertInstanceOf(RuntimeException::class, $caughtException);
            $this->assertSame($case['message'], $caughtException->getMessage());
        }

        [$unassessedEnrollment, $unassessedProfile] = $this->paymentContext(withAssessment: false);

        try {
            $service->confirmManualPayment(
                enrollmentId: $unassessedEnrollment->id,
                amount: '50.00',
                channel: 'cash',
                paymentReference: 'OR-NO-ASSESSMENT',
                actor: $accounting,
            );

            $this->fail('An unassessed enrollment should reject payment confirmation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Enrollment must be assessed before payment confirmation.', $exception->getMessage());
        }

        $this->assertDatabaseCount(Payment::class, 0);
        $this->assertSame('1000.00', $studentProfile->fresh()->current_balance);
        $this->assertSame('1000.00', $unassessedProfile->fresh()->current_balance);
    }

    public function test_manual_confirmation_rejects_a_future_payment_date(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext();

        try {
            app(PaymentConfirmationService::class)->confirmManualPayment(
                enrollmentId: $enrollment->id,
                amount: '50.00',
                channel: 'cash',
                paymentReference: 'OR-FUTURE',
                actor: $accounting,
                confirmedAt: CarbonImmutable::now(config('app.timezone'))->addMinute(),
            );

            $this->fail('A future payment date should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payment confirmation date cannot be in the future.', $exception->getMessage());
        }

        $this->assertDatabaseCount(Payment::class, 0);
        $this->assertSame('1000.00', $studentProfile->fresh()->current_balance);
    }

    public function test_overpayment_remains_a_standard_payment_and_creates_a_negative_balance(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext(
            assessmentAmount: '100.00',
            currentBalance: '100.00',
        );

        $summary = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $enrollment->id,
            amount: '125.00',
            channel: 'bank_transfer',
            paymentReference: 'BANK-OVERPAYMENT',
            actor: $accounting,
        );

        $this->assertSame('-25.00', $summary['current_balance']);
        $this->assertTrue($summary['finance_cleared']);
        $this->assertSame('-25.00', $studentProfile->fresh()->current_balance);
        $this->assertSame('pre_enrolled', $enrollment->fresh()->status);
        $this->assertDatabaseHas(LedgerEntry::class, [
            'id' => $summary['ledger_entry_id'],
            'entry_type' => 'payment',
            'amount' => '-125.00',
            'running_balance' => '-25.00',
        ]);
        $this->assertDatabaseMissing(LedgerEntry::class, [
            'enrollment_id' => $enrollment->id,
            'entry_type' => 'wallet_deposit',
        ]);
    }

    public function test_shared_clearance_does_not_clear_an_unassessed_enrollment_with_a_negative_balance(): void
    {
        [$enrollment, $studentProfile] = $this->paymentContext(
            currentBalance: '-50.00',
            withAssessment: false,
        );

        $summary = app(EnrollmentFinanceClearanceService::class)->clearIfEligible(
            enrollment: $enrollment,
            studentProfile: $studentProfile,
            currentBalance: '-50.00',
            actor: null,
            timestamp: CarbonImmutable::now(config('app.timezone')),
        );

        $this->assertFalse($summary['finance_cleared']);
        $this->assertSame('0.00', $summary['minimum_required_payment']);
        $this->assertSame('pending_payment', $enrollment->fresh()->status);
    }

    public function test_real_full_payment_clears_finance_and_settles_active_promissory_note(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext();

        $note = PromissoryNote::query()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'amount' => '1000.00',
            'due_date' => now(config('app.timezone'))->addDay()->toDateString(),
            'status' => 'active',
            'reason' => 'Approved payment promise.',
            'approved_by' => $accounting->id,
            'approved_at' => now(config('app.timezone')),
        ]);

        $summary = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $enrollment->id,
            amount: '1000.00',
            channel: 'gcash_manual',
            paymentReference: 'GCASH-PROMISSORY',
            actor: $accounting,
        );

        $this->assertSame('0.00', $summary['current_balance']);
        $this->assertTrue($summary['finance_cleared']);
        $this->assertSame('pre_enrolled', $enrollment->fresh()->status);
        $this->assertSame(PromissoryNote::StatusSettled, $note->refresh()->status);
    }

    public function test_finance_clearance_secures_every_matching_capacity_plan(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext(
            assessmentAmount: '100.00',
            currentBalance: '100.00',
        );
        $campusPlan = AdmissionCapacityPlan::factory()->create([
            'term_id' => $enrollment->term_id,
            'scope_type' => AdmissionCapacityPlan::ScopeCampus,
            'capacity_limit' => 2,
            'reserved_count' => 0,
        ]);
        $programPlan = AdmissionCapacityPlan::factory()->create([
            'term_id' => $enrollment->term_id,
            'scope_type' => AdmissionCapacityPlan::ScopeProgram,
            'program_id' => $studentProfile->program_id,
            'capacity_limit' => 1,
            'reserved_count' => 0,
        ]);

        $summary = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $enrollment->id,
            amount: '100.00',
            channel: 'cash',
            paymentReference: 'OR-CAPACITY',
            actor: $accounting,
        );

        $this->assertTrue($summary['finance_cleared']);
        $this->assertSame(2, AdmissionCapacityReservation::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertSame(1, $campusPlan->refresh()->reserved_count);
        $this->assertSame(1, $programPlan->refresh()->reserved_count);
        $this->assertDatabaseHas(AdmissionCapacityReservation::class, [
            'admission_capacity_plan_id' => $campusPlan->id,
            'enrollment_id' => $enrollment->id,
            'status' => AdmissionCapacityReservation::StatusSecured,
        ]);
    }

    public function test_capacity_reservation_is_idempotent_for_already_cleared_enrollment(): void
    {
        [$enrollment, $studentProfile] = $this->paymentContext(
            assessmentAmount: '100.00',
            currentBalance: '0.00',
        );
        $plan = AdmissionCapacityPlan::factory()->create([
            'term_id' => $enrollment->term_id,
            'scope_type' => AdmissionCapacityPlan::ScopeCampus,
            'capacity_limit' => 1,
            'reserved_count' => 0,
        ]);
        $timestamp = CarbonImmutable::now(config('app.timezone'));

        $service = app(EnrollmentFinanceClearanceService::class);
        $first = $service->clearIfEligible($enrollment, $studentProfile, '0.00', null, $timestamp);
        $second = $service->clearIfEligible($enrollment->fresh(), $studentProfile->fresh(), '0.00', null, $timestamp->addMinute());

        $this->assertTrue($first['finance_cleared']);
        $this->assertTrue($second['finance_cleared']);
        $this->assertSame(1, AdmissionCapacityReservation::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertSame(1, $plan->refresh()->reserved_count);
    }

    public function test_full_capacity_blocks_finance_clearance_and_rolls_back_payment(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext(
            assessmentAmount: '100.00',
            currentBalance: '100.00',
        );
        AdmissionCapacityPlan::factory()->create([
            'term_id' => $enrollment->term_id,
            'scope_type' => AdmissionCapacityPlan::ScopeCampus,
            'capacity_limit' => 1,
            'reserved_count' => 1,
        ]);

        try {
            app(PaymentConfirmationService::class)->confirmManualPayment(
                enrollmentId: $enrollment->id,
                amount: '100.00',
                channel: 'cash',
                paymentReference: 'OR-FULL-CAPACITY',
                actor: $accounting,
            );

            $this->fail('Expected full capacity to block finance clearance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('admission_capacity', $exception->errors());
        }

        $this->assertSame('pending_payment', $enrollment->fresh()->status);
        $this->assertDatabaseMissing(Payment::class, [
            'payment_reference' => 'OR-FULL-CAPACITY',
        ]);
        $this->assertSame('100.00', $studentProfile->fresh()->current_balance);
        $this->assertSame(0, AdmissionCapacityReservation::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_admission_finance_clearance_requires_an_approved_capacity_plan_before_handover(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->admissionPaymentContext(
            withCapacityPlan: false,
        );

        try {
            app(PaymentConfirmationService::class)->confirmManualPayment(
                enrollmentId: $enrollment->id,
                amount: '100.00',
                channel: 'cash',
                paymentReference: 'OR-ADMISSION-NO-CAPACITY',
                actor: $accounting,
            );

            $this->fail('Expected admission readiness to require an approved capacity plan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('admission_capacity', $exception->errors());
        }

        $this->assertSame('pending_payment', $enrollment->fresh()->status);
        $this->assertDatabaseMissing(Payment::class, [
            'payment_reference' => 'OR-ADMISSION-NO-CAPACITY',
        ]);
        $this->assertSame('100.00', $studentProfile->fresh()->current_balance);
        $this->assertSame(0, AdmissionCapacityReservation::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_admission_finance_clearance_requires_a_published_schedule_before_handover(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->admissionPaymentContext(
            withPublishedSchedule: false,
        );

        try {
            app(PaymentConfirmationService::class)->confirmManualPayment(
                enrollmentId: $enrollment->id,
                amount: '100.00',
                channel: 'cash',
                paymentReference: 'OR-ADMISSION-NO-SCHEDULE',
                actor: $accounting,
            );

            $this->fail('Expected admission readiness to require a published schedule.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('schedule_publish', $exception->errors());
        }

        $this->assertSame('pending_payment', $enrollment->fresh()->status);
        $this->assertDatabaseMissing(Payment::class, [
            'payment_reference' => 'OR-ADMISSION-NO-SCHEDULE',
        ]);
        $this->assertSame('100.00', $studentProfile->fresh()->current_balance);
        $this->assertSame(0, AdmissionCapacityReservation::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_admission_finance_clearance_completes_when_admin_readiness_is_configured(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->admissionPaymentContext();

        $summary = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $enrollment->id,
            amount: '100.00',
            channel: 'cash',
            paymentReference: 'OR-ADMISSION-READY',
            actor: $accounting,
        );

        $this->assertTrue($summary['finance_cleared']);
        $this->assertSame('pre_enrolled', $enrollment->fresh()->status);
        $this->assertSame('0.00', $studentProfile->fresh()->current_balance);
        $this->assertSame(1, AdmissionCapacityReservation::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_finance_clearance_failure_rolls_back_payment_ledger_balance_and_audit(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext();

        $failingClearance = new class extends EnrollmentFinanceClearanceService
        {
            public function __construct() {}

            public function clearIfEligible(
                Enrollment $enrollment,
                StudentProfile $studentProfile,
                string $currentBalance,
                ?User $actor,
                CarbonImmutable $timestamp,
            ): array {
                throw new RuntimeException('Simulated clearance failure.');
            }
        };
        $service = new PaymentConfirmationService(new DecimalMoney, $failingClearance, app(PromissoryNoteLifecycleService::class));

        try {
            $service->confirmManualPayment(
                enrollmentId: $enrollment->id,
                amount: '100.00',
                channel: 'cash',
                paymentReference: 'OR-ROLLBACK',
                actor: $accounting,
            );

            $this->fail('A downstream clearance failure should roll back the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated clearance failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount(Payment::class, 0);
        $this->assertSame(0, LedgerEntry::query()->where('entry_type', 'payment')->count());
        $this->assertSame('1000.00', $studentProfile->fresh()->current_balance);
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'payment_confirmation')->count());
    }

    /**
     * @return array{Enrollment, StudentProfile, User}
     */
    private function paymentContext(
        string $assessmentAmount = '1000.00',
        string $currentBalance = '1000.00',
        bool $withAssessment = true,
    ): array {
        $term = Term::factory()->create();
        $studentProfile = StudentProfile::factory()->create([
            'current_balance' => $currentBalance,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'status' => 'pending_payment',
        ]);

        if ($withAssessment) {
            LedgerEntry::factory()->create([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'entry_type' => 'assessment',
                'reference_type' => 'fee_template',
                'reference_id' => null,
                'description' => 'Tuition assessment',
                'amount' => $assessmentAmount,
                'running_balance' => $assessmentAmount,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $accounting = User::factory()->create();
        $accounting->givePermissionTo(Permission::findOrCreate('process-payments'));

        return [$enrollment, $studentProfile, $accounting];
    }

    /**
     * @return array{Enrollment, StudentProfile, User}
     */
    private function admissionPaymentContext(
        bool $withCapacityPlan = true,
        bool $withPublishedSchedule = true,
    ): array {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext(
            assessmentAmount: '100.00',
            currentBalance: '100.00',
        );

        $this->materializeApplicantChecklist($enrollment, $studentProfile);
        [$section, $group, $subject, $faculty] = $this->readySchedulingSetup($enrollment, $studentProfile);

        if ($withCapacityPlan) {
            AdmissionCapacityPlan::factory()->create([
                'term_id' => $enrollment->term_id,
                'scope_type' => AdmissionCapacityPlan::ScopeProgram,
                'program_id' => $studentProfile->program_id,
                'year_level' => $enrollment->year_level,
                'delivery_setup' => $enrollment->modality,
                'capacity_limit' => 1,
                'reserved_count' => 0,
            ]);
        }

        if ($withPublishedSchedule) {
            $this->publishedSchedule($enrollment, $section, $group, $subject, $faculty);
        }

        return [$enrollment, $studentProfile, $accounting];
    }

    private function materializeApplicantChecklist(Enrollment $enrollment, StudentProfile $studentProfile): void
    {
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $studentProfile->user_id,
            'term_id' => $enrollment->term_id,
            'program_id' => $studentProfile->program_id,
            'year_level' => $enrollment->year_level,
            'applicant_type' => ApplicantIntake::ApplicantTypeNew,
            'preferred_modality' => $enrollment->modality,
            'status' => ApplicantIntake::StatusApproved,
            'duplicate_check_status' => ApplicantIntake::DuplicateStatusClear,
            'approved_at' => now(),
        ]);
        $offering = AdmissionOffering::factory()->create([
            'term_id' => $enrollment->term_id,
            'program_id' => $studentProfile->program_id,
            'year_level' => $enrollment->year_level,
        ]);
        $policy = AdmissionRequirementPolicy::factory()->create([
            'admission_offering_id' => $offering->id,
        ]);
        $item = DocumentRequirementItem::factory()->create([
            'admission_requirement_policy_id' => $policy->id,
        ]);

        ApplicantDocumentRequirement::factory()->create([
            'applicant_intake_id' => $intake->id,
            'admission_offering_id' => $offering->id,
            'admission_requirement_policy_id' => $policy->id,
            'document_requirement_item_id' => $item->id,
            'item_key' => $item->key,
            'label' => $item->label,
            'gate_type' => $item->gate_type,
            'evidence_state' => ApplicantDocumentRequirement::EvidenceStateSatisfied,
        ]);
    }

    /**
     * @return array{Section, SectionDeliveryGroup, Subject, User}
     */
    private function readySchedulingSetup(Enrollment $enrollment, StudentProfile $studentProfile): array
    {
        $curriculum = Curriculum::factory()->create([
            'program_id' => $studentProfile->program_id,
        ]);
        $section = Section::factory()->create([
            'term_id' => $enrollment->term_id,
            'program_id' => $studentProfile->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => $enrollment->year_level,
            'curriculum_period' => '1st Semester',
            'max_seats' => 30,
            'enrolled_count' => 0,
            'modality' => $enrollment->modality,
        ]);
        $group = SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'modality' => $enrollment->modality,
            'capacity' => 30,
            'assigned_count' => 0,
            'room_required' => true,
            'room' => 'R-101',
            'status' => SectionDeliveryGroup::StatusActive,
        ]);
        $subject = Subject::factory()->create();
        CurriculumSubject::factory()->create([
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'year_level' => $enrollment->year_level,
            'semester' => '1st Semester',
        ]);
        CurriculumReadinessScope::query()->updateOrCreate(
            [
                'curriculum_id' => $curriculum->id,
                'year_level' => $enrollment->year_level,
                'curriculum_period' => '1st Semester',
            ],
            [
                'status' => CurriculumReadinessScope::StatusReadyForScheduling,
                'last_transition_at' => now(),
                'last_blockers' => [],
            ],
        );

        $faculty = User::factory()->create();
        $registrar = User::factory()->create();
        $period = FacultyAvailabilityPeriod::factory()->create([
            'term_id' => $enrollment->term_id,
            'status' => FacultyAvailabilityPeriod::StatusLocked,
            'created_by' => $registrar->id,
            'locked_at' => now(),
        ]);
        $submission = FacultyAvailabilitySubmission::factory()->create([
            'term_id' => $enrollment->term_id,
            'availability_period_id' => $period->id,
            'faculty_id' => $faculty->id,
            'status' => FacultyAvailabilitySubmission::StatusLocked,
            'locked_at' => now(),
            'approved_by' => $registrar->id,
            'approved_at' => now(),
        ]);
        FacultyAvailabilityWindow::factory()->create([
            'submission_id' => $submission->id,
        ]);
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => $enrollment->term_id,
            'approved_by' => $registrar->id,
        ]);

        return [$section, $group, $subject, $faculty];
    }

    private function publishedSchedule(
        Enrollment $enrollment,
        Section $section,
        SectionDeliveryGroup $group,
        Subject $subject,
        User $faculty,
    ): void {
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $enrollment->term_id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $faculty->id,
            'generated_at' => now(),
            'committed_by' => $faculty->id,
            'committed_at' => now(),
            'published_by' => $faculty->id,
            'published_at' => now(),
            'constraint_summary' => [],
        ]);

        SectionMeeting::query()->create([
            'term_id' => $enrollment->term_id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $group->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'R-101',
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'modality' => $enrollment->modality,
            'schedule_generation_run_id' => $run->id,
            'committed_by' => $faculty->id,
            'committed_at' => now(),
        ]);
    }
}
