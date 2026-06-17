<?php

namespace Tests\Feature;

use App\Actions\Finance\EnrollmentFinanceClearanceService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PromissoryNote;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_active_promissory_note_does_not_clear_finance_status_after_full_payment_posting(): void
    {
        [$enrollment, $studentProfile, $accounting] = $this->paymentContext();

        PromissoryNote::query()->create([
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
        $this->assertFalse($summary['finance_cleared']);
        $this->assertSame('pending_payment', $enrollment->fresh()->status);
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
        $service = new PaymentConfirmationService(new DecimalMoney, $failingClearance);

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
}
