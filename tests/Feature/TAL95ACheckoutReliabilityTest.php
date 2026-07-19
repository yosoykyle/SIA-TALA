<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\CreatePaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentCheckoutException;
use App\Actions\Integrations\Payments\PaymentCheckoutRequest;
use App\Actions\Integrations\Payments\PaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentGateway;
use App\Actions\Integrations\Payments\PaymentGatewayException;
use App\Actions\Integrations\Payments\PayMongoPaymentGateway;
use App\Filament\Student\Pages\Finance;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL95ACheckoutReliabilityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    public function test_checkout_derives_the_student_and_due_and_persists_before_provider_creation(): void
    {
        $fixture = $this->checkoutFixture();
        $gateway = new RecordingPaymentGateway;
        $gateway->beforeCreate = function (PaymentCheckoutRequest $request, string $idempotencyKey) use ($fixture): void {
            $this->assertSame($fixture['profile']->id, $request->studentProfileId);
            $this->assertSame($fixture['assessment']->id, $request->assessmentId);
            $this->assertSame('2000.00', $request->amount);

            $attempt = PaymentAttempt::query()->where('internal_reference', $idempotencyKey)->sole();
            $this->assertSame('pending', $attempt->status);
            $this->assertNull($attempt->provider_checkout_id);
        };
        $this->app->instance(PaymentGateway::class, $gateway);

        $result = app(CreatePaymentCheckoutSession::class)->create(
            actor: $fixture['student'],
            assessmentId: $fixture['assessment']->id,
            successUrl: 'https://tala.test/student/finance?checkout=success',
            cancelUrl: 'https://tala.test/student/finance?checkout=cancelled',
        );

        $attempt = PaymentAttempt::query()
            ->where('assessment_id', $fixture['assessment']->id)
            ->sole();
        $this->assertSame(1, $gateway->createCalls);
        $this->assertSame($attempt->id, $result['payment_attempt_id']);
        $this->assertSame('2000.00', (string) $attempt->amount);
        $this->assertSame($fixture['profile']->id, $attempt->student_profile_id);
        $this->assertSame($fixture['assessment']->id, $attempt->assessment_id);
        $this->assertNull($attempt->expires_at);
        $this->assertSame('mock_checkout_1', $attempt->provider_checkout_id);

        $activity = Activity::query()
            ->where('event', 'payment_checkout_attempt_created')
            ->where('subject_id', $attempt->id)
            ->sole();
        $this->assertSame($fixture['student']->id, $activity->causer_id);
        $this->assertSame($attempt->id, $activity->subject_id);
        $this->assertArrayNotHasKey('checkout_url', $activity->properties->all());
    }

    public function test_repeated_checkout_reuses_one_provider_active_attempt(): void
    {
        $fixture = $this->checkoutFixture();
        $gateway = new RecordingPaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        $creator = app(CreatePaymentCheckoutSession::class);

        $first = $creator->create(actor: $fixture['student'], assessmentId: $fixture['assessment']->id);
        $second = $creator->create(actor: $fixture['student'], assessmentId: $fixture['assessment']->id);

        $this->assertSame($first['payment_attempt_id'], $second['payment_attempt_id']);
        $this->assertSame('reused', $second['outcome']);
        $this->assertSame(1, $gateway->createCalls);
        $this->assertSame(1, PaymentAttempt::query()->where('assessment_id', $fixture['assessment']->id)->count());
    }

    public function test_changed_due_expires_the_old_provider_session_before_creating_a_replacement(): void
    {
        $fixture = $this->checkoutFixture();
        $gateway = new RecordingPaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        $creator = app(CreatePaymentCheckoutSession::class);

        $first = $creator->create(actor: $fixture['student']);
        PaymentScheduleRow::query()->where('assessment_id', $fixture['assessment']->id)->update(['amount' => '2500.00']);
        $second = $creator->create(actor: $fixture['student']);

        $this->assertNotSame($first['payment_attempt_id'], $second['payment_attempt_id']);
        $this->assertSame(2, $gateway->createCalls);
        $this->assertSame(1, $gateway->expireCalls);
        $this->assertSame('expired', PaymentAttempt::query()->findOrFail($first['payment_attempt_id'])->status);
        $this->assertSame('pending', PaymentAttempt::query()->findOrFail($second['payment_attempt_id'])->status);
        $this->assertSame('2500.00', $second['amount']);
    }

    public function test_student_finance_checkout_ignores_tampered_livewire_due_state(): void
    {
        $fixture = $this->checkoutFixture();
        $gateway = new RecordingPaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->set('finance.current_due_amount', '0.01')
            ->callAction('checkout')
            ->assertRedirect('https://mock-payments.test/checkout/mock_checkout_1');

        $attempt = PaymentAttempt::query()
            ->where('assessment_id', $fixture['assessment']->id)
            ->sole();

        $this->assertSame('2000.00', (string) $attempt->amount);
    }

    public function test_student_finance_checkout_action_reuses_the_active_attempt(): void
    {
        $fixture = $this->checkoutFixture();
        $gateway = new RecordingPaymentGateway;
        $this->app->instance(PaymentGateway::class, $gateway);

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->callAction('checkout')
            ->assertRedirect('https://mock-payments.test/checkout/mock_checkout_1');

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->callAction('checkout')
            ->assertRedirect('https://mock-payments.test/checkout/mock_checkout_1');

        $this->assertSame(1, $gateway->createCalls);
        $this->assertSame(1, PaymentAttempt::query()
            ->where('assessment_id', $fixture['assessment']->id)
            ->count());
    }

    public function test_student_finance_checkout_action_shows_a_safe_provider_failure(): void
    {
        $fixture = $this->checkoutFixture();
        $gateway = new RecordingPaymentGateway;
        $gateway->createException = new PaymentGatewayException(
            message: 'Provider detail must not be displayed.',
            errorCode: 'parameter_invalid',
            retryable: false,
            indeterminate: false,
            httpStatus: 422,
        );
        $this->app->instance(PaymentGateway::class, $gateway);

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->callAction('checkout')
            ->assertNotified('Payment checkout is temporarily unavailable. Please try again later.');

        $attempt = PaymentAttempt::query()
            ->where('assessment_id', $fixture['assessment']->id)
            ->sole();

        $this->assertSame('failed', $attempt->status);
        $this->assertStringNotContainsString('Provider detail', json_encode($attempt->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_student_finance_checkout_action_uses_the_paymongo_v2_contract(): void
    {
        $fixture = $this->checkoutFixture();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.paymongo.com/v2/checkout_sessions' => Http::response($this->payMongoSessionPayload('active')),
        ]);
        $this->app->instance(PaymentGateway::class, $this->payMongoGateway());

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->callAction('checkout')
            ->assertRedirect('https://checkout.paymongo.com/cs_tal95a');

        $attempt = PaymentAttempt::query()
            ->where('assessment_id', $fixture['assessment']->id)
            ->sole();

        $this->assertSame('cs_tal95a', $attempt->provider_checkout_id);
        $this->assertSame('2000.00', (string) $attempt->amount);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
            && $request['data']['attributes']['reference_number'] === $attempt->internal_reference
            && $request['data']['attributes']['line_items'][0]['amount'] === 200000);
    }

    public function test_student_finance_disables_checkout_when_no_positive_due_exists(): void
    {
        $fixture = $this->checkoutFixture(positiveDue: false);

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->assertActionDisabled('checkout');
    }

    public function test_database_rejects_a_second_pending_or_under_review_attempt_for_one_assessment(): void
    {
        $fixture = $this->checkoutFixture();
        $this->paymentAttempt($fixture, 'under_review', 'TALA-PAY-REVIEW');

        $this->expectException(QueryException::class);

        $this->paymentAttempt($fixture, 'pending', 'TALA-PAY-PENDING');
    }

    public function test_paymongo_creation_uses_v2_reference_and_idempotency_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.paymongo.com/v2/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_tal95a',
                    'type' => 'checkout_session',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_tal95a',
                        'status' => 'active',
                        'livemode' => false,
                    ],
                ],
            ]),
        ]);
        $gateway = new PayMongoPaymentGateway(
            money: app(DecimalMoney::class),
            baseUrl: 'https://api.paymongo.com',
            secretKey: 'sk_test_not_a_real_secret',
            paymentMethodTypes: ['gcash', 'card'],
        );
        $request = new PaymentCheckoutRequest(
            studentProfileId: 10,
            amount: '2000.00',
            description: 'TALA current finance amount due',
            assessmentId: 20,
            successUrl: 'https://tala.test/student/finance?checkout=success',
            cancelUrl: 'https://tala.test/student/finance?checkout=cancelled',
            metadata: ['tala_reference' => 'TALA-PAY-IDEMPOTENT'],
        );

        $session = $gateway->createCheckoutSession($request, 'TALA-PAY-IDEMPOTENT');

        $this->assertSame('active', $session->status);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
                && $request->hasHeader('Idempotency-Key', 'TALA-PAY-IDEMPOTENT')
                && $request['data']['attributes']['reference_number'] === 'TALA-PAY-IDEMPOTENT'
                && $request['data']['attributes']['line_items'][0]['amount'] === 200000;
        });
    }

    public function test_paymongo_retrieve_and_expire_use_v1_without_creation_idempotency_headers(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions/cs_tal95a' => Http::response($this->payMongoSessionPayload('active')),
            'https://api.paymongo.com/v1/checkout_sessions/cs_tal95a/expire' => Http::response($this->payMongoSessionPayload('expired')),
        ]);
        $gateway = $this->payMongoGateway();

        $retrieved = $gateway->retrieveCheckoutSession('cs_tal95a');
        $expired = $gateway->expireCheckoutSession('cs_tal95a');

        $this->assertSame('active', $retrieved->status);
        $this->assertSame('expired', $expired->status);
        Http::assertSent(function (Request $request): bool {
            return in_array($request->method(), ['GET', 'POST'], true)
                && ! $request->hasHeader('Idempotency-Key');
        });
        Http::assertSentCount(2);
    }

    public function test_definite_provider_rejection_marks_the_local_attempt_failed_with_sanitized_diagnostics(): void
    {
        $fixture = $this->checkoutFixture();
        $gateway = new RecordingPaymentGateway;
        $gateway->createException = new PaymentGatewayException(
            message: 'Provider detail must not be persisted.',
            errorCode: 'parameter_invalid',
            retryable: false,
            indeterminate: false,
            httpStatus: 422,
        );
        $this->app->instance(PaymentGateway::class, $gateway);

        try {
            app(CreatePaymentCheckoutSession::class)->create(actor: $fixture['student']);
            $this->fail('The checkout should have been rejected.');
        } catch (PaymentCheckoutException $exception) {
            $this->assertSame('Payment checkout is temporarily unavailable. Please try again later.', $exception->getMessage());
        }

        $attempt = PaymentAttempt::query()
            ->where('assessment_id', $fixture['assessment']->id)
            ->sole();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('parameter_invalid', data_get($attempt->metadata, 'gateway_error.code'));
        $this->assertStringNotContainsString('Provider detail', json_encode($attempt->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_indeterminate_provider_failure_preserves_the_pending_attempt_for_idempotent_recovery(): void
    {
        $fixture = $this->checkoutFixture();
        $gateway = new RecordingPaymentGateway;
        $gateway->createException = new PaymentGatewayException(
            message: 'Connection failed after dispatch.',
            errorCode: 'connection_failed',
            retryable: true,
            indeterminate: true,
        );
        $this->app->instance(PaymentGateway::class, $gateway);

        try {
            app(CreatePaymentCheckoutSession::class)->create(actor: $fixture['student']);
            $this->fail('The checkout should have remained unresolved.');
        } catch (PaymentCheckoutException) {
            // The student receives only the safe checkout failure.
        }

        $attempt = PaymentAttempt::query()
            ->where('assessment_id', $fixture['assessment']->id)
            ->sole();
        $this->assertSame('pending', $attempt->status);
        $this->assertNull($attempt->provider_checkout_id);
        $this->assertTrue((bool) data_get($attempt->metadata, 'gateway_error.indeterminate'));
    }

    public function test_sandbox_command_requires_an_existing_active_assessment_before_any_provider_call(): void
    {
        config()->set('tala_integrations.payments.driver', 'paymongo');
        config()->set('tala_integrations.payments.paymongo.base_url', 'https://api.paymongo.com');
        config()->set('tala_integrations.payments.paymongo.secret_key', 'sk_test_tal95a_not_real');
        config()->set('tala_integrations.payments.paymongo.livemode', false);

        $paymentAttemptCount = PaymentAttempt::query()->count();

        $exitCode = Artisan::call('integrations:paymongo-sandbox-checkout');

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--assessment-id', Artisan::output());
        $this->assertSame(0, User::query()->where('email', 'paymongo-sandbox@tala.test')->count());
        $this->assertSame($paymentAttemptCount, PaymentAttempt::query()->count());
    }

    /**
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,assessment:Assessment}
     */
    private function checkoutFixture(bool $positiveDue = true): array
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $student->assignRole('student');
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->for($student)->for($program)->create();
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'pending_payment',
            'registered_at' => now()->subDay(),
        ]);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => $positiveDue ? '9000.00' : '0.00',
            'discount_total' => '0.00',
            'total' => $positiveDue ? '9000.00' : '0.00',
            'required_downpayment' => $positiveDue ? '2000.00' : '0.00',
            'activated_at' => now(),
        ]);
        PaymentScheduleRow::query()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'category' => PaymentScheduleRow::CategoryDownpayment,
            'due_date' => now()->addWeek()->toDateString(),
            'amount' => $positiveDue ? '2000.00' : '1.00',
            'state' => $positiveDue ? PaymentScheduleRow::StateDue : 'paid',
        ]);

        if ($positiveDue) {
            LedgerEntry::query()->create([
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'direction' => LedgerEntry::DirectionCharge,
                'category' => 'tuition',
                'amount' => '9000.00',
                'source_type' => Assessment::class,
                'source_id' => $assessment->id,
                'description' => 'TAL-95A checkout fixture',
                'posted_at' => now(),
                'state' => 'posted',
            ]);
        }

        return compact('student', 'profile', 'term', 'enrollment', 'assessment');
    }

    /**
     * @param  array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,assessment:Assessment}  $fixture
     */
    private function paymentAttempt(array $fixture, string $status, string $reference): PaymentAttempt
    {
        return PaymentAttempt::query()->create([
            'assessment_id' => $fixture['assessment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'channel' => 'paymongo',
            'provider' => 'mock',
            'internal_reference' => $reference,
            'amount' => '2000.00',
            'currency' => 'PHP',
            'status' => $status,
            'metadata' => [],
        ]);
    }

    private function payMongoGateway(): PayMongoPaymentGateway
    {
        return new PayMongoPaymentGateway(
            money: app(DecimalMoney::class),
            baseUrl: 'https://api.paymongo.com',
            secretKey: 'sk_test_not_a_real_secret',
            paymentMethodTypes: ['gcash', 'card'],
        );
    }

    /** @return array<string, mixed> */
    private function payMongoSessionPayload(string $status): array
    {
        return [
            'data' => [
                'id' => 'cs_tal95a',
                'type' => 'checkout_session',
                'attributes' => [
                    'checkout_url' => 'https://checkout.paymongo.com/cs_tal95a',
                    'status' => $status,
                    'livemode' => false,
                ],
            ],
        ];
    }
}

final class RecordingPaymentGateway implements PaymentGateway
{
    public int $createCalls = 0;

    public int $expireCalls = 0;

    public ?Closure $beforeCreate = null;

    public ?PaymentGatewayException $createException = null;

    /** @var array<string, PaymentCheckoutSession> */
    private array $sessions = [];

    public function provider(): string
    {
        return 'mock';
    }

    public function createCheckoutSession(PaymentCheckoutRequest $request, string $idempotencyKey = ''): PaymentCheckoutSession
    {
        $this->createCalls++;

        if ($this->beforeCreate instanceof Closure) {
            ($this->beforeCreate)($request, $idempotencyKey);
        }

        if ($this->createException instanceof PaymentGatewayException) {
            throw $this->createException;
        }

        $session = new PaymentCheckoutSession(
            provider: 'mock',
            checkoutSessionId: 'mock_checkout_'.$this->createCalls,
            checkoutUrl: 'https://mock-payments.test/checkout/mock_checkout_'.$this->createCalls,
            status: 'active',
            metadata: ['driver' => 'recording'],
        );
        $this->sessions[$session->checkoutSessionId] = $session;

        return $session;
    }

    public function retrieveCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        return $this->sessions[$checkoutSessionId];
    }

    public function expireCheckoutSession(string $checkoutSessionId): PaymentCheckoutSession
    {
        $this->expireCalls++;
        $session = $this->sessions[$checkoutSessionId];
        $expired = new PaymentCheckoutSession(
            provider: $session->provider,
            checkoutSessionId: $session->checkoutSessionId,
            checkoutUrl: $session->checkoutUrl,
            status: 'expired',
            metadata: $session->metadata,
        );
        $this->sessions[$checkoutSessionId] = $expired;

        return $expired;
    }
}
