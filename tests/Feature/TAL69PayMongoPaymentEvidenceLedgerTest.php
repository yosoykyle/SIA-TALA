<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Models\Assessment;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL69PayMongoPaymentEvidenceLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private EnrollmentAssessmentService $assessments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleAccounting,
            User::StaffRoleRegistrar,
            User::StaffRoleFaculty,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->assessments = app(EnrollmentAssessmentService::class);
    }

    public function test_verified_paymongo_webhook_creates_payment_evidence_and_posts_one_ledger_payment(): void
    {
        $assessment = $this->activeAssessment();
        $attempt = $this->paymentAttempt($assessment, '1000.00', 'cs_tal69_paid');
        $webhookCallId = $this->webhookCall($this->paidPayload($attempt, 'evt_tal69_paid', 100000));

        $result = app(PayMongoWebhookProcessor::class)->process($webhookCallId);

        $payment = Payment::query()->sole();
        $ledgerEntry = LedgerEntry::query()
            ->where('direction', LedgerEntry::DirectionPayment)
            ->sole();

        $this->assertSame('posted', $result['status']);
        $this->assertSame($payment->id, $result['payment_id']);
        $this->assertSame($ledgerEntry->id, $result['ledger_entry_id']);
        $this->assertSame('verified', $payment->evidence_status);
        $this->assertSame('paymongo:cs_tal69_paid', $payment->provider_reference);
        $this->assertSame($payment->id, $ledgerEntry->payment_id);
        $this->assertSame(Payment::class, $ledgerEntry->source_type);
        $this->assertSame($payment->id, $ledgerEntry->source_id);
        $this->assertSame('1000.00', (string) $ledgerEntry->amount);
        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame('4800.00', $this->ledgerBalanceFor($assessment->enrollment->studentProfile));
    }

    public function test_duplicate_paymongo_webhook_resolves_to_existing_payment_and_ledger_without_reposting(): void
    {
        $assessment = $this->activeAssessment();
        $attempt = $this->paymentAttempt($assessment, '1000.00', 'cs_tal69_duplicate');
        $payload = $this->paidPayload($attempt, 'evt_tal69_duplicate', 100000);
        $processor = app(PayMongoWebhookProcessor::class);

        $first = $processor->process($this->webhookCall($payload));
        $second = $processor->process($this->webhookCall($payload));

        $this->assertSame('posted', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame($first['payment_id'], $second['payment_id']);
        $this->assertSame($first['ledger_entry_id'], $second['ledger_entry_id']);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
    }

    public function test_amount_mismatch_keeps_evidence_under_review_without_posting_ledger(): void
    {
        $assessment = $this->activeAssessment();
        $attempt = $this->paymentAttempt($assessment, '1000.00', 'cs_tal69_amount_review');
        $webhookCallId = $this->webhookCall($this->paidPayload($attempt, 'evt_tal69_amount_review', 99900));

        $result = app(PayMongoWebhookProcessor::class)->process($webhookCallId);

        $payment = Payment::query()->sole();

        $this->assertSame('review_required', $result['status']);
        $this->assertSame('amount_mismatch', $result['reason']);
        $this->assertSame('under_review', $payment->evidence_status);
        $this->assertSame('999.00', (string) $payment->amount);
        $this->assertSame('under_review', $attempt->fresh()->status);
        $this->assertSame(0, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
    }

    public function test_currency_and_reference_mismatches_stay_under_review_without_posting_ledger(): void
    {
        $assessment = $this->activeAssessment();
        $currencyAttempt = $this->paymentAttempt($assessment, '1000.00', 'cs_tal69_currency_review');
        $referenceAttempt = $this->paymentAttempt($assessment, '1000.00', 'cs_tal69_reference_review');
        $processor = app(PayMongoWebhookProcessor::class);

        $currencyResult = $processor->process($this->webhookCall(
            $this->paidPayload($currencyAttempt, 'evt_tal69_currency_review', 100000, currency: 'USD'),
        ));
        $referenceResult = $processor->process($this->webhookCall(
            $this->paidPayload($referenceAttempt, 'evt_tal69_reference_review', 100000, talaReference: 'TALA-PAY-WRONG'),
        ));

        $this->assertSame('currency_mismatch', $currencyResult['reason']);
        $this->assertSame('reference_mismatch', $referenceResult['reason']);
        $this->assertSame(2, Payment::query()->where('evidence_status', 'under_review')->count());
        $this->assertSame(0, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
    }

    public function test_unknown_paymongo_reference_is_marked_for_review_without_payment_or_ledger_rows(): void
    {
        $this->activeAssessment();
        $payload = $this->paymongoPayload(
            eventId: 'evt_tal69_unknown',
            checkoutSessionId: 'cs_unknown_tal69',
            amountCentavos: 100000,
            talaReference: 'TALA-PAY-UNKNOWN',
        );
        $webhookCallId = $this->webhookCall($payload);

        $result = app(PayMongoWebhookProcessor::class)->process($webhookCallId);

        $this->assertSame('review_required', $result['status']);
        $this->assertSame('unknown_reference', $result['reason']);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
        $this->assertStringContainsString(
            'unknown_reference',
            (string) DB::table('webhook_calls')->where('id', $webhookCallId)->value('exception'),
        );
    }

    private function activeAssessment(): Assessment
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00, 2.00]);
        $this->configureMvpFeeRules($enrollment);
        $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));

        return $this->assessments->activate($assessment, $accounting, CarbonImmutable::parse('2026-06-11 09:00:00'));
    }

    private function paymentAttempt(Assessment $assessment, string $amount, string $checkoutSessionId): PaymentAttempt
    {
        return PaymentAttempt::query()->create([
            'assessment_id' => $assessment->id,
            'student_profile_id' => $assessment->enrollment->student_profile_id,
            'channel' => 'paymongo',
            'provider' => 'paymongo',
            'internal_reference' => 'TALA-PAY-'.Str::upper((string) Str::uuid()),
            'provider_checkout_id' => $checkoutSessionId,
            'provider_intent_id' => null,
            'amount' => $amount,
            'currency' => 'PHP',
            'status' => 'pending',
            'metadata' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paidPayload(
        PaymentAttempt $attempt,
        string $eventId,
        int $amountCentavos,
        string $currency = 'PHP',
        ?string $talaReference = null,
    ): array {
        return $this->paymongoPayload(
            eventId: $eventId,
            checkoutSessionId: (string) $attempt->provider_checkout_id,
            amountCentavos: $amountCentavos,
            currency: $currency,
            talaReference: $talaReference ?? (string) $attempt->internal_reference,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function paymongoPayload(
        string $eventId,
        string $checkoutSessionId,
        int $amountCentavos,
        string $currency = 'PHP',
        string $talaReference = 'TALA-PAY-REFERENCE',
    ): array {
        return [
            'data' => [
                'id' => $eventId,
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => $checkoutSessionId,
                        'type' => 'checkout_session',
                        'attributes' => [
                            'amount_paid' => $amountCentavos,
                            'currency' => $currency,
                            'metadata' => [
                                'tala_reference' => $talaReference,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhookCall(array $payload): int
    {
        $now = CarbonImmutable::now(config('app.timezone'))->toDateTimeString();

        return (int) DB::table('webhook_calls')->insertGetId([
            'name' => 'paymongo',
            'url' => 'https://tala.test/api/webhooks/paymongo',
            'headers' => json_encode([], JSON_UNESCAPED_SLASHES),
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  list<float>  $units
     */
    private function placedEnrollmentWithCourseUnits(array $units): Enrollment
    {
        $term = Term::factory()->create([
            'starts_on' => '2026-08-01',
        ]);
        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateActive,
        ]);
        $studentProfile = StudentProfile::factory()->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
        ]);
        $enrollment = Enrollment::factory()
            ->for($studentProfile)
            ->for($term)
            ->create(['status' => 'pending_payment']);

        foreach ($units as $unit) {
            $specification = CourseSpecification::factory()->create([
                'credit_units' => $unit,
                'state' => CourseSpecification::StateActive,
            ]);
            $entry = CurriculumEntry::factory()
                ->for($curriculum)
                ->for($specification, 'courseSpecification')
                ->create();
            $offering = TermOffering::factory()
                ->for($term)
                ->for($entry, 'curriculumEntry')
                ->create([
                    'state' => TermOffering::StateScheduled,
                ]);

            CourseEnrollment::query()->create([
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $offering->id,
                'status' => CourseEnrollment::StatusActive,
                'units_snapshot' => number_format($unit, 2, '.', ''),
                'added_at' => now(),
            ]);
        }

        return $enrollment->refresh();
    }

    private function configureMvpFeeRules(Enrollment $enrollment): void
    {
        $programId = $enrollment->studentProfile->program_id;
        $termId = $enrollment->term_id;

        $this->feeRule('TUITION', FeeRule::DisplayCategoryTuition, FeeRule::CalculationPerUnit, rate: '900.00');
        $this->feeRule('LAB', FeeRule::DisplayCategoryLaboratory, FeeRule::CalculationFixed, amount: '300.00', programId: $programId, termId: $termId);
        $this->feeRule('MISC', FeeRule::DisplayCategoryMiscellaneous, FeeRule::CalculationFixed, amount: '650.00', programId: $programId, termId: $termId);
        $this->feeRule('OTHER', FeeRule::DisplayCategoryOther, FeeRule::CalculationFixed, amount: '250.00', termId: $termId);
        $this->feeRule('REG', FeeRule::DisplayCategoryRegistration, FeeRule::CalculationFixed, amount: '100.00', programId: $programId);
        $this->feeRule('DOWNPAYMENT', FeeRule::DisplayCategoryDownpayment, FeeRule::CalculationFixed, amount: '1500.00', programId: $programId, termId: $termId);
    }

    private function feeRule(
        string $code,
        string $displayCategory,
        string $calculationType,
        ?string $amount = null,
        ?string $rate = null,
        ?int $programId = null,
        ?int $termId = null,
    ): FeeRule {
        return FeeRule::query()->create([
            'code' => $code,
            'name' => str($displayCategory)->headline()->toString(),
            'ledger_category' => $displayCategory === FeeRule::DisplayCategoryDownpayment ? FeeRule::LedgerCategoryDownpayment : FeeRule::LedgerCategoryCharge,
            'display_category' => $displayCategory,
            'program_id' => $programId,
            'term_id' => $termId,
            'calculation_type' => $calculationType,
            'amount' => $amount,
            'rate' => $rate,
            'effective_from' => '2026-06-01',
            'effective_until' => null,
            'is_active' => true,
            'authority' => 'TAL-69 payment evidence test fee matrix',
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function ledgerBalanceFor(StudentProfile $studentProfile): string
    {
        $money = app(DecimalMoney::class);
        $entries = LedgerEntry::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('state', 'posted')
            ->get(['direction', 'amount']);

        $balance = '0.00';

        foreach ($entries as $entry) {
            $amount = (string) $entry->amount;
            $balance = match ($entry->direction) {
                LedgerEntry::DirectionPayment,
                LedgerEntry::DirectionDiscount,
                LedgerEntry::DirectionScholarship,
                LedgerEntry::DirectionWaiver,
                LedgerEntry::DirectionReversal => $money->subtract($balance, $amount),
                default => $money->add($balance, $amount),
            };
        }

        return $balance;
    }
}
