<?php

namespace Tests\Feature;

use App\Actions\Enrollment\StudentEnrollmentService;
use App\Actions\Finance\EnrollmentFinanceClearanceService;
use App\Models\ApplicantIntake;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FinancialAccommodation;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL86EFinanceGateSourceBehaviorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach (['student', 'applicant'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_posted_ledger_payment_equal_to_required_downpayment_clears_finance_gate(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $this->verifiedPostedPayment($fixture, amount: '1500.00', orNumber: 'OR-86E-POSTED');

        $clearance = $this->clearanceService()->clearIfEligible(
            $fixture['enrollment'],
            $fixture['profile'],
            '4300.00',
            null,
            CarbonImmutable::parse('2026-07-01 09:00:00'),
        );

        $this->assertTrue($clearance['finance_cleared']);
        $this->assertSame('posted_ledger_payment', $clearance['finance_clearance_source']);
        $this->assertSame('1500.00', $clearance['minimum_required_payment']);
        $this->assertSame('1500.00', $clearance['total_confirmed_payments']);
        $this->assertSame('pending_payment', $clearance['enrollment_status']);
    }

    public function test_first_term_applicant_with_approved_intake_clears_finance_without_capacity_crash(): void
    {
        // TAL-93F2 regression: a first-term student with an approved ApplicantIntake for the term
        // previously reached the retired admission-capacity path during finance clearance
        // (a tableless admission-capacity readiness query -> SQL crash). Clearance must now succeed.
        $fixture = $this->activeAssessmentFixture();

        ApplicantIntake::factory()
            ->approved()
            ->create([
                'user_id' => $fixture['student']->id,
                'term_id' => $fixture['term']->id,
                'program_id' => $fixture['profile']->program_id,
            ]);

        $this->verifiedPostedPayment($fixture, amount: '1500.00', orNumber: 'OR-93F2-POSTED');

        $clearance = $this->clearanceService()->clearIfEligible(
            $fixture['enrollment'],
            $fixture['profile'],
            '4300.00',
            null,
            CarbonImmutable::parse('2026-07-01 09:00:00'),
        );

        $this->assertTrue($clearance['finance_cleared']);
        $this->assertSame('posted_ledger_payment', $clearance['finance_clearance_source']);
        $this->assertSame('pending_payment', $clearance['enrollment_status']);
        $this->assertSame(1, ApplicantIntake::query()
            ->where('user_id', $fixture['student']->id)
            ->where('term_id', $fixture['term']->id)
            ->where('status', ApplicantIntake::StatusApproved)
            ->count());
    }

    public function test_active_current_term_financial_accommodation_with_explicit_effect_clears_without_payment_ledger(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $this->accommodationFor($fixture, [
            'allows_finance_gate' => true,
            'waives_downpayment' => false,
        ]);

        $clearance = $this->clearanceService()->clearIfEligible(
            $fixture['enrollment'],
            $fixture['profile'],
            '5800.00',
            null,
            CarbonImmutable::parse('2026-07-01 09:00:00'),
        );

        $this->assertTrue($clearance['finance_cleared']);
        $this->assertSame('active_financial_accommodation', $clearance['finance_clearance_source']);
        $this->assertSame('0.00', $clearance['total_confirmed_payments']);
        $this->assertSame('pending_payment', $clearance['enrollment_status']);
        $this->assertSame(0, LedgerEntry::query()
            ->where('enrollment_id', $fixture['enrollment']->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
    }

    public function test_expired_cancelled_wrong_term_and_missing_effect_accommodations_do_not_clear_finance_gate(): void
    {
        foreach ($this->invalidAccommodationCases() as $case) {
            $fixture = $this->activeAssessmentFixture();
            $this->accommodationFor($fixture, $case['overrides']);

            $clearance = $this->clearanceService()->clearIfEligible(
                $fixture['enrollment'],
                $fixture['profile'],
                '5800.00',
                null,
                CarbonImmutable::parse('2026-07-01 09:00:00'),
            );

            $this->assertFalse($clearance['finance_cleared'], $case['label']);
            $this->assertSame('none', $clearance['finance_clearance_source'], $case['label']);
            $this->assertSame('pending_payment', $clearance['enrollment_status'], $case['label']);
        }
    }

    public function test_pending_payment_attempt_does_not_clear_finance_gate(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $this->paymentAttempt($fixture, 'pending');

        $clearance = $this->clearanceService()->clearIfEligible(
            $fixture['enrollment'],
            $fixture['profile'],
            '5800.00',
            null,
            CarbonImmutable::parse('2026-07-01 09:00:00'),
        );

        $this->assertFalse($clearance['finance_cleared']);
        $this->assertSame('none', $clearance['finance_clearance_source']);
        $this->assertSame(0, Payment::query()
            ->where('student_profile_id', $fixture['profile']->id)
            ->where('term_id', $fixture['term']->id)
            ->count());
        $this->assertSame(0, LedgerEntry::query()
            ->where('enrollment_id', $fixture['enrollment']->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
    }

    public function test_under_review_payment_evidence_does_not_clear_finance_gate_without_ledger_posting(): void
    {
        $fixture = $this->activeAssessmentFixture();
        Payment::factory()
            ->for($fixture['profile'])
            ->for($fixture['term'])
            ->create([
                'method' => 'paymongo',
                'channel' => 'paymongo',
                'amount' => '1500.00',
                'evidence_status' => 'under_review',
                'paid_at' => now()->subHour(),
                'verified_at' => null,
                'provider_reference' => 'paymongo:under-review-86e',
            ]);

        $clearance = $this->clearanceService()->clearIfEligible(
            $fixture['enrollment'],
            $fixture['profile'],
            '5800.00',
            null,
            CarbonImmutable::parse('2026-07-01 09:00:00'),
        );

        $this->assertFalse($clearance['finance_cleared']);
        $this->assertSame('none', $clearance['finance_clearance_source']);
        $this->assertSame(0, LedgerEntry::query()
            ->where('enrollment_id', $fixture['enrollment']->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
    }

    public function test_or_mapping_absence_does_not_block_verified_evidence_with_posted_ledger_payment(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $payment = $this->verifiedPostedPayment($fixture, amount: '1500.00', orNumber: null);

        $clearance = $this->clearanceService()->clearIfEligible(
            $fixture['enrollment'],
            $fixture['profile'],
            '4300.00',
            null,
            CarbonImmutable::parse('2026-07-01 09:00:00'),
        );

        $this->assertNull($payment->fresh()->or_number);
        $this->assertTrue($clearance['finance_cleared']);
        $this->assertSame('posted_ledger_payment', $clearance['finance_clearance_source']);
    }

    public function test_checkout_success_return_is_not_authoritative_without_verified_webhook_evidence_and_posted_ledger_entry(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $this->paymentAttempt($fixture, PaymentAttempt::StatusPending, [
            'return_status' => 'success',
            'browser_returned_at' => '2026-07-01T09:00:00+08:00',
        ]);

        $clearance = $this->clearanceService()->clearIfEligible(
            $fixture['enrollment'],
            $fixture['profile'],
            '5800.00',
            null,
            CarbonImmutable::parse('2026-07-01 09:00:00'),
        );

        $this->assertFalse($clearance['finance_cleared']);
        $this->assertSame('none', $clearance['finance_clearance_source']);
        $this->assertSame(0, Payment::query()
            ->where('student_profile_id', $fixture['profile']->id)
            ->where('term_id', $fixture['term']->id)
            ->count());
        $this->assertSame(0, LedgerEntry::query()
            ->where('enrollment_id', $fixture['enrollment']->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
    }

    /**
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,assessment:Assessment}
     */
    private function activeAssessmentFixture(): array
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);

        $program = Program::factory()->create();
        $profile = StudentProfile::factory()
            ->for($student)
            ->for($program)
            ->create();
        $term = Term::factory()->create([
            'label' => 'First Semester 2026-2027',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-10-31',
        ]);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['status' => 'pending_payment']);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '5800.00',
            'discount_total' => '0.00',
            'total' => '5800.00',
            'required_downpayment' => '1500.00',
            'activated_at' => now()->subDay(),
        ]);

        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'assessment',
            'amount' => '5800.00',
            'source_type' => Assessment::class,
            'source_id' => $assessment->id,
            'description' => 'Active assessment charge',
            'posted_at' => now()->subHours(2),
            'state' => 'posted',
        ]);

        return [
            'student' => $student,
            'profile' => $profile,
            'term' => $term,
            'enrollment' => $enrollment,
            'assessment' => $assessment,
        ];
    }

    /**
     * @param  array{profile:StudentProfile,term:Term,enrollment:Enrollment}  $fixture
     */
    private function verifiedPostedPayment(array $fixture, string $amount, ?string $orNumber): Payment
    {
        $payment = Payment::factory()
            ->for($fixture['profile'])
            ->for($fixture['term'])
            ->create([
                'method' => 'paymongo',
                'channel' => 'paymongo',
                'amount' => $amount,
                'evidence_status' => 'verified',
                'paid_at' => now()->subHour(),
                'verified_at' => now()->subMinutes(50),
                'provider_reference' => 'paymongo:verified-'.Str::lower((string) Str::uuid()),
                'or_number' => $orNumber,
            ]);

        LedgerEntry::query()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'direction' => LedgerEntry::DirectionPayment,
            'category' => 'payment',
            'amount' => $payment->amount,
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'payment_id' => $payment->id,
            'description' => 'Verified posted payment',
            'posted_at' => now()->subMinutes(45),
            'state' => 'posted',
        ]);

        return $payment->refresh();
    }

    /**
     * @param  array{profile:StudentProfile,term:Term}  $fixture
     * @param  array<string, mixed>  $overrides
     */
    private function accommodationFor(array $fixture, array $overrides = []): FinancialAccommodation
    {
        return FinancialAccommodation::query()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'balance_snapshot' => '5800.00',
            'covered_amount' => '1500.00',
            'basis' => FinancialAccommodation::BasisInstitutionalAccommodation,
            'certification_reference' => null,
            'private_evidence_reference' => null,
            'promissory_required' => false,
            'promissory_maker' => null,
            'allows_finance_gate' => false,
            'allows_next_term_enrollment' => false,
            'allows_reactivation' => false,
            'allows_record_release' => false,
            'waives_downpayment' => false,
            'authority' => 'Accounting Director',
            'status' => FinancialAccommodation::StatusActive,
            'effective_from' => '2026-06-01',
            'expires_on' => '2026-08-01',
            ...$overrides,
        ]);
    }

    /**
     * @param  array{profile:StudentProfile,assessment:Assessment}  $fixture
     * @param  array<string, mixed>  $metadata
     */
    private function paymentAttempt(array $fixture, string $status, array $metadata = []): PaymentAttempt
    {
        return PaymentAttempt::query()->create([
            'assessment_id' => $fixture['assessment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'channel' => 'paymongo',
            'provider' => 'paymongo',
            'internal_reference' => 'TALA-PAY-'.Str::upper((string) Str::uuid()),
            'provider_checkout_id' => 'checkout_'.Str::lower((string) Str::uuid()),
            'provider_intent_id' => null,
            'amount' => '1500.00',
            'currency' => 'PHP',
            'status' => $status,
            'expires_at' => now()->addHour(),
            'paid_at' => null,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return list<array{label:string,overrides:array<string, mixed>}>
     */
    private function invalidAccommodationCases(): array
    {
        return [
            [
                'label' => 'wrong term accommodation',
                'overrides' => [
                    'term_id' => Term::factory()->create()->id,
                    'allows_finance_gate' => true,
                ],
            ],
            [
                'label' => 'expired accommodation',
                'overrides' => [
                    'allows_finance_gate' => true,
                    'effective_from' => '2026-05-01',
                    'expires_on' => '2026-06-30',
                ],
            ],
            [
                'label' => 'cancelled accommodation',
                'overrides' => [
                    'allows_finance_gate' => true,
                    'status' => FinancialAccommodation::StatusCancelled,
                ],
            ],
            [
                'label' => 'missing finance gate effect',
                'overrides' => [
                    'allows_finance_gate' => false,
                    'waives_downpayment' => true,
                ],
            ],
        ];
    }

    private function clearanceService(): EnrollmentFinanceClearanceService
    {
        return new EnrollmentFinanceClearanceService(
            app(DecimalMoney::class),
            app(StudentEnrollmentService::class),
        );
    }
}
