<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\CreatePaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentCheckoutRequest;
use App\Actions\Integrations\Payments\PaymentGateway;
use App\Actions\Integrations\Payments\PayMongoPaymentGateway;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests the mock payment gateway, ensuring that simulated transactions
 * and payment flows function correctly without hitting real endpoints.
 *
 * Steps / Test Cases:
 * 1. test_mock_payment_gateway_creates_pending_attempt_without_external_requests
 * 2. test_paymongo_driver_resolves_for_sandbox_checkout
 */
class MockPaymentGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareSchema();
    }

    public function test_mock_payment_gateway_creates_pending_attempt_without_external_requests(): void
    {
        Http::preventStrayRequests();
        $this->configureMockGateway();

        $studentProfileId = $this->studentProfileId();

        $session = app(CreatePaymentCheckoutSession::class)->create(new PaymentCheckoutRequest(
            studentProfileId: $studentProfileId,
            amount: '1500',
            description: 'Enrollment downpayment',
            channel: 'paymongo',
            termId: 202601,
            enrollmentId: 9001,
            successUrl: 'https://tala.test/payments/success',
            cancelUrl: 'https://tala.test/payments/cancel',
            metadata: ['module' => 'enrollment'],
        ));

        $this->assertSame('mock', $session['provider']);
        $this->assertSame('pending', $session['status']);
        $this->assertSame('1500.00', $session['amount']);
        $this->assertStringStartsWith('mock_checkout_', $session['provider_checkout_session_id']);
        $this->assertStringStartsWith('https://mock-payments.test/checkout/mock_checkout_', $session['checkout_url']);

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $session['payment_attempt_id'],
            'student_profile_id' => $studentProfileId,
            'term_id' => 202601,
            'enrollment_id' => 9001,
            'channel' => 'paymongo',
            'status' => 'pending',
            'provider' => 'mock',
            'amount' => '1500.00',
        ]);

        $meta = json_decode((string) DB::table('payment_attempts')->where('id', $session['payment_attempt_id'])->value('meta'), true);

        $this->assertSame('enrollment', $meta['request']['module']);
        $this->assertSame($session['checkout_url'], $meta['checkout_url']);
    }

    public function test_paymongo_driver_resolves_for_sandbox_checkout(): void
    {
        config([
            'tala_integrations.payments.driver' => 'paymongo',
            'tala_integrations.payments.paymongo.secret_key' => 'sk_test_local',
        ]);
        $this->app->forgetInstance(PaymentGateway::class);

        $this->assertInstanceOf(PayMongoPaymentGateway::class, app(PaymentGateway::class));
    }

    private function configureMockGateway(): void
    {
        config([
            'tala_integrations.payments.driver' => 'mock',
            'tala_integrations.payments.mock.provider' => 'mock',
            'tala_integrations.payments.mock.checkout_base_url' => 'https://mock-payments.test/checkout',
        ]);

        $this->app->forgetInstance(PaymentGateway::class);
    }

    private function studentProfileId(): int
    {
        return (int) DB::table('student_profiles')->insertGetId([
            'user_id' => 1,
            'student_id' => 'SIA-2026-0001',
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
