<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\CreatePaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentCheckoutRequest;
use App\Actions\Integrations\Payments\PaymentGateway;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests the integration with the PayMongo payment gateway, validating
 * transaction initialization, status checks, and API interactions.
 *
 * Steps / Test Cases:
 * 1. test_paymongo_gateway_creates_pending_attempt_from_checkout_session_response
 * 2. test_paymongo_gateway_requires_secret_key
 * 3. test_paymongo_gateway_does_not_create_attempt_when_provider_rejects_request
 */
class PayMongoPaymentGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchema();
    }

    public function test_paymongo_gateway_creates_pending_attempt_from_checkout_session_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.paymongo.test/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_test_123',
                    'type' => 'checkout_session',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.test/cs_test_123',
                        'status' => 'active',
                        'livemode' => false,
                        'payment_intent' => [
                            'id' => 'pi_test_123',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->configurePayMongoGateway();

        $studentProfileId = $this->studentProfileId();

        $session = app(CreatePaymentCheckoutSession::class)->create(new PaymentCheckoutRequest(
            studentProfileId: $studentProfileId,
            amount: '1500.00',
            description: 'Enrollment downpayment',
            channel: 'paymongo',
            termId: 202601,
            enrollmentId: 9001,
            successUrl: 'https://tala.test/payments/success',
            cancelUrl: 'https://tala.test/payments/cancel',
            metadata: ['module' => 'enrollment'],
        ));

        $this->assertSame('paymongo', $session['provider']);
        $this->assertSame('pending', $session['status']);
        $this->assertSame('cs_test_123', $session['provider_checkout_session_id']);
        $this->assertSame('https://checkout.paymongo.test/cs_test_123', $session['checkout_url']);

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $session['payment_attempt_id'],
            'student_profile_id' => $studentProfileId,
            'provider' => 'paymongo',
            'provider_checkout_session_id' => 'cs_test_123',
            'provider_payment_intent_id' => 'pi_test_123',
            'status' => 'pending',
            'amount' => '1500.00',
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.paymongo.test/v1/checkout_sessions'
                && $request->hasHeader('Authorization')
                && $request['data']['attributes']['line_items'][0]['amount'] === 150000
                && $request['data']['attributes']['line_items'][0]['currency'] === 'PHP'
                && $request['data']['attributes']['line_items'][0]['quantity'] === 1
                && $request['data']['attributes']['payment_method_types'] === ['gcash', 'card']
                && $request['data']['attributes']['success_url'] === 'https://tala.test/payments/success'
                && $request['data']['attributes']['cancel_url'] === 'https://tala.test/payments/cancel';
        });
    }

    public function test_paymongo_gateway_requires_secret_key(): void
    {
        Http::preventStrayRequests();
        $this->configurePayMongoGateway(secretKey: null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PayMongo secret key is not configured.');

        app(CreatePaymentCheckoutSession::class)->create(new PaymentCheckoutRequest(
            studentProfileId: $this->studentProfileId(),
            amount: '100.00',
            description: 'Enrollment downpayment',
        ));

        $this->assertSame(0, DB::table('payment_attempts')->count());
    }

    public function test_paymongo_gateway_does_not_create_attempt_when_provider_rejects_request(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.paymongo.test/v1/checkout_sessions' => Http::response([
                'errors' => [
                    ['detail' => 'Invalid checkout session payload.'],
                ],
            ], 400),
        ]);

        $this->configurePayMongoGateway();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PayMongo checkout session could not be created.');

        app(CreatePaymentCheckoutSession::class)->create(new PaymentCheckoutRequest(
            studentProfileId: $this->studentProfileId(),
            amount: '100.00',
            description: 'Enrollment downpayment',
        ));

        $this->assertSame(0, DB::table('payment_attempts')->count());
    }

    private function configurePayMongoGateway(?string $secretKey = 'sk_test_local'): void
    {
        config([
            'tala_integrations.payments.driver' => 'paymongo',
            'tala_integrations.payments.paymongo.base_url' => 'https://api.paymongo.test/v1',
            'tala_integrations.payments.paymongo.secret_key' => $secretKey,
            'tala_integrations.payments.paymongo.payment_method_types' => ['gcash', 'card'],
        ]);

        $this->app->forgetInstance(PaymentGateway::class);
    }

    private function studentProfileId(): int
    {
        return (int) DB::table('student_profiles')->insertGetId([
            'user_id' => 1,
            'student_id' => 'SIA-2026-0001',
            'education_level' => 'college',
            'year_level' => '1st Year',
            'current_balance' => '1500.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function prepareSchema(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('student_profiles');

        Schema::create('student_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('student_id')->unique();
            $table->string('education_level');
            $table->string('year_level')->nullable();
            $table->decimal('current_balance', 12, 2)->default('0.00');
            $table->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_profile_id');
            $table->unsignedBigInteger('term_id')->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('ledger_entry_id')->nullable();
            $table->string('channel')->index();
            $table->string('status')->default('pending')->index();
            $table->string('provider')->nullable()->index();
            $table->string('provider_event_id')->nullable();
            $table->string('provider_checkout_session_id')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->string('provider_payment_intent_id')->nullable();
            $table->string('webhook_idempotency_key')->nullable();
            $table->decimal('amount', 12, 2);
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }
}
