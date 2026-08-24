<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\ExactDuePaymentSnapshotService;
use App\Actions\Integrations\Payments\PayMongoCheckoutRecoveryService;
use App\Filament\Pages\SystemHealth;
use App\Filament\Student\Pages\Finance;
use App\Mail\PaymentPostedMail;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D3CFinancePayMongoHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        foreach (['student', User::StaffRoleAccounting, User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        config()->set('tala_integrations.payments.driver', 'paymongo');
        config()->set('tala_integrations.payments.paymongo.base_url', 'https://api.paymongo.com');
        config()->set('tala_integrations.payments.paymongo.public_key', 'pk_test_tal96d3c_not_real');
        config()->set('tala_integrations.payments.paymongo.secret_key', 'sk_test_tal96d3c_not_real');
        config()->set('tala_integrations.payments.paymongo.webhook_signature', 'whsk_tal96d3c_not_real');
        config()->set('tala_integrations.payments.paymongo.livemode', false);
    }

    #[Test]
    public function test_student_checkout_returns_are_informational_and_never_claim_payment_posting(): void
    {
        $fixture = $this->paymentFixture();

        Livewire::withQueryParams(['checkout' => 'success'])
            ->actingAs($fixture['student'])
            ->test(Finance::class)
            ->assertDontSee('Checkout completed')
            ->assertDontSee('Waiting for verified payment confirmation')
            ->assertSee('Due through as-of')
            ->assertSee('Remaining Term balance')
            ->assertSee('Submit payment evidence');

        Livewire::withQueryParams(['checkout' => 'cancelled'])
            ->actingAs($fixture['student'])
            ->test(Finance::class)
            ->assertDontSee('Checkout cancelled')
            ->assertSee('Submit payment evidence');

        $this->assertSame(0, Payment::query()
            ->where('payment_attempt_id', $fixture['attempt']->id)
            ->count());
        $this->assertSame(0, LedgerEntry::query()
            ->where('student_profile_id', $fixture['profile']->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
    }

    #[Test]
    public function test_paid_provider_recovery_creates_sanitized_review_evidence_without_posting(): void
    {
        $fixture = $this->paymentFixture();
        $this->fakeCheckoutRetrieval($fixture['attempt'], payments: [
            $this->providerPayment($fixture['attempt']),
        ]);

        $result = app(PayMongoCheckoutRecoveryService::class)->recover(
            $fixture['attempt']->id,
            $fixture['accounting'],
        );
        $event = OperationalEvent::query()->findOrFail($result['event_id']);

        $this->assertSame('review_required', $result['status']);
        $this->assertSame(OperationalEvent::StatusReviewRequired, $event->status);
        $this->assertSame(OperationalEvent::ChannelProviderApi, $event->channel);
        $this->assertSame('recovered_paid_without_webhook', data_get($event->diagnostics, 'reason'));
        $this->assertSame($fixture['attempt']->provider_checkout_id, data_get($event->payload, 'checkout_session_id'));
        $this->assertSame('pay_tal96d3c', data_get($event->payload, 'payment.id'));
        $this->assertArrayNotHasKey('checkout_url', $event->payload ?? []);
        $this->assertArrayNotHasKey('raw_response', $event->payload ?? []);
        $this->assertSame(PaymentAttempt::StatusReviewRequired, $fixture['attempt']->fresh()->status);
        $this->assertSame(0, Payment::query()
            ->where('payment_attempt_id', $fixture['attempt']->id)
            ->count());
        $this->assertSame(0, LedgerEntry::query()
            ->where('student_profile_id', $fixture['profile']->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
    }

    #[Test]
    public function test_accounting_confirmation_of_exact_recovered_evidence_posts_once_and_notifies_once(): void
    {
        Mail::fake();
        $fixture = $this->paymentFixture();
        $fixture['attempt']->forceFill(['provider_intent_id' => null])->save();
        $payment = $this->providerPayment($fixture['attempt']);
        data_set($payment, 'attributes.payment_intent_id', 'pi_recovered_tal96d3c');
        $this->fakeCheckoutRetrieval($fixture['attempt'], payments: [
            $payment,
        ]);
        $recovered = app(PayMongoCheckoutRecoveryService::class)->recover(
            $fixture['attempt']->id,
            $fixture['accounting'],
        );

        $service = app(PayMongoCheckoutRecoveryService::class);
        $first = $service->confirm(
            $recovered['event_id'],
            'Accounting matched the provider payment to the exact TALA checkout and assessment.',
            $fixture['accounting'],
        );
        $second = $service->confirm(
            $recovered['event_id'],
            'Repeated confirmation remains idempotent.',
            $fixture['accounting'],
        );

        $payment = Payment::query()->where('payment_attempt_id', $fixture['attempt']->id)->sole();

        $this->assertSame('confirmed', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame('verified', $payment->evidence_status);
        $this->assertSame('paymongo:pay_tal96d3c', $payment->provider_reference);
        $this->assertSame($fixture['accounting']->id, $payment->verified_by);
        $this->assertSame(PaymentAttempt::StatusConfirmed, $fixture['attempt']->fresh()->status);
        $this->assertSame('pi_recovered_tal96d3c', $fixture['attempt']->fresh()->provider_intent_id);
        $this->assertSame(1, LedgerEntry::query()
            ->where('payment_id', $payment->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
        $this->assertSame(OperationalEvent::StatusProcessed, OperationalEvent::query()->findOrFail($recovered['event_id'])->status);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'paymongo_recovered_payment_confirmed')->count());
        Mail::assertQueued(PaymentPostedMail::class, 1);
    }

    #[Test]
    public function test_pending_failed_and_expired_provider_sessions_update_only_the_attempt_state(): void
    {
        $pending = $this->paymentFixture();
        $this->fakeCheckoutRetrieval($pending['attempt'], payments: []);

        $pendingResult = app(PayMongoCheckoutRecoveryService::class)->recover(
            $pending['attempt']->id,
            $pending['accounting'],
        );

        $this->assertSame(PaymentAttempt::StatusPending, $pendingResult['status']);
        $this->assertSame(PaymentAttempt::StatusPending, $pending['attempt']->fresh()->status);

        $failed = $this->paymentFixture();
        $this->fakeCheckoutRetrieval($failed['attempt'], payments: [
            $this->providerPayment($failed['attempt'], status: 'failed'),
        ]);

        $failedResult = app(PayMongoCheckoutRecoveryService::class)->recover(
            $failed['attempt']->id,
            $failed['accounting'],
        );

        $this->assertSame(PaymentAttempt::StatusFailed, $failedResult['status']);
        $this->assertSame(PaymentAttempt::StatusFailed, $failed['attempt']->fresh()->status);

        $expired = $this->paymentFixture();
        $this->fakeCheckoutRetrieval($expired['attempt'], status: 'expired', payments: []);

        $expiredResult = app(PayMongoCheckoutRecoveryService::class)->recover(
            $expired['attempt']->id,
            $expired['accounting'],
        );

        $this->assertSame(PaymentAttempt::StatusExpired, $expiredResult['status']);
        $this->assertSame(PaymentAttempt::StatusExpired, $expired['attempt']->fresh()->status);
        $this->assertSame(0, Payment::query()
            ->whereIn('payment_attempt_id', [$pending['attempt']->id, $failed['attempt']->id, $expired['attempt']->id])
            ->count());
        $this->assertSame(0, LedgerEntry::query()
            ->whereIn('student_profile_id', [$pending['profile']->id, $failed['profile']->id, $expired['profile']->id])
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
    }

    #[Test]
    public function test_recovered_payment_mismatch_cannot_be_confirmed_or_posted(): void
    {
        $fixture = $this->paymentFixture();
        $this->fakeCheckoutRetrieval($fixture['attempt'], payments: [
            $this->providerPayment($fixture['attempt'], amount: 99900),
        ]);
        $recovered = app(PayMongoCheckoutRecoveryService::class)->recover(
            $fixture['attempt']->id,
            $fixture['accounting'],
        );

        try {
            app(PayMongoCheckoutRecoveryService::class)->confirm(
                $recovered['event_id'],
                'Attempting to confirm mismatched provider evidence.',
                $fixture['accounting'],
            );
            $this->fail('Mismatched recovered evidence must not be confirmed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not exactly match', $exception->getMessage());
        }

        $this->assertSame(PaymentAttempt::StatusReviewRequired, $fixture['attempt']->fresh()->status);
        $this->assertSame(0, Payment::query()
            ->where('payment_attempt_id', $fixture['attempt']->id)
            ->count());
        $this->assertSame(0, LedgerEntry::query()
            ->where('student_profile_id', $fixture['profile']->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
    }

    #[Test]
    public function test_recovered_payment_cannot_replace_an_existing_provider_intent(): void
    {
        $fixture = $this->paymentFixture();
        $payment = $this->providerPayment($fixture['attempt']);
        data_set($payment, 'attributes.payment_intent_id', 'pi_different_tal96d3c');
        $this->fakeCheckoutRetrieval($fixture['attempt'], payments: [$payment]);
        $recovered = app(PayMongoCheckoutRecoveryService::class)->recover(
            $fixture['attempt']->id,
            $fixture['accounting'],
        );

        try {
            app(PayMongoCheckoutRecoveryService::class)->confirm(
                $recovered['event_id'],
                'Attempting to replace the Payment Attempt provider intent.',
                $fixture['accounting'],
            );
            $this->fail('A recovered payment must not replace an existing provider intent.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not exactly match', $exception->getMessage());
        }

        $this->assertSame(PaymentAttempt::StatusReviewRequired, $fixture['attempt']->fresh()->status);
        $this->assertSame(0, Payment::query()
            ->where('payment_attempt_id', $fixture['attempt']->id)
            ->count());
    }

    #[Test]
    public function test_incomplete_provider_safety_fields_cannot_be_confirmed_or_posted(): void
    {
        foreach (['livemode', 'disputed', 'refunds'] as $missingField) {
            $fixture = $this->paymentFixture();
            $payment = $this->providerPayment($fixture['attempt']);
            data_forget($payment, 'attributes.'.$missingField);
            $this->fakeCheckoutRetrieval($fixture['attempt'], payments: [$payment]);
            $recovered = app(PayMongoCheckoutRecoveryService::class)->recover(
                $fixture['attempt']->id,
                $fixture['accounting'],
            );
            $rejected = false;

            try {
                app(PayMongoCheckoutRecoveryService::class)->confirm(
                    $recovered['event_id'],
                    "Attempting to confirm provider evidence without {$missingField}.",
                    $fixture['accounting'],
                );
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('does not exactly match', $exception->getMessage());
                $rejected = true;
            }

            $this->assertTrue($rejected, "Provider evidence without {$missingField} must not be confirmed.");
            $this->assertSame(PaymentAttempt::StatusReviewRequired, $fixture['attempt']->fresh()->status);
            $this->assertSame(0, Payment::query()
                ->where('payment_attempt_id', $fixture['attempt']->id)
                ->count());
            $this->assertSame(0, LedgerEntry::query()
                ->where('student_profile_id', $fixture['profile']->id)
                ->where('direction', LedgerEntry::DirectionPayment)
                ->count());
        }
    }

    #[Test]
    public function test_accounting_can_reject_unposted_recovered_evidence_with_an_audited_reason(): void
    {
        $fixture = $this->paymentFixture();
        $this->fakeCheckoutRetrieval($fixture['attempt'], payments: [
            $this->providerPayment($fixture['attempt']),
        ]);
        $recovered = app(PayMongoCheckoutRecoveryService::class)->recover(
            $fixture['attempt']->id,
            $fixture['accounting'],
        );

        $result = app(PayMongoCheckoutRecoveryService::class)->reject(
            $recovered['event_id'],
            'Accounting rejected the provider recovery after institutional review.',
            $fixture['accounting'],
        );
        $event = OperationalEvent::query()->findOrFail($recovered['event_id']);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame(PaymentAttempt::StatusFailed, $fixture['attempt']->fresh()->status);
        $this->assertSame(OperationalEvent::StatusProcessed, $event->status);
        $this->assertSame('rejected', data_get($event->diagnostics, 'resolution.action'));
        $this->assertSame(0, Payment::query()
            ->where('payment_attempt_id', $fixture['attempt']->id)
            ->count());
        $this->assertSame(0, LedgerEntry::query()
            ->where('student_profile_id', $fixture['profile']->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
        $this->assertSame(1, DB::table('activity_log')
            ->where('event', 'paymongo_recovered_payment_rejected')
            ->where('subject_id', $event->id)
            ->count());
    }

    #[Test]
    public function test_only_accounting_can_recover_or_decide_provider_evidence(): void
    {
        $fixture = $this->paymentFixture();
        $this->fakeCheckoutRetrieval($fixture['attempt'], payments: [
            $this->providerPayment($fixture['attempt']),
        ]);

        $this->expectException(AuthorizationException::class);

        app(PayMongoCheckoutRecoveryService::class)->recover(
            $fixture['attempt']->id,
            $fixture['registrar'],
        );
    }

    #[Test]
    public function test_reconciliation_and_integration_surfaces_explain_recovery_without_exposing_secrets(): void
    {
        $fixture = $this->paymentFixture();
        $this->fakeCheckoutRetrieval($fixture['attempt'], payments: [
            $this->providerPayment($fixture['attempt']),
        ]);
        $recovered = app(PayMongoCheckoutRecoveryService::class)->recover(
            $fixture['attempt']->id,
            $fixture['accounting'],
        );
        $event = OperationalEvent::query()->findOrFail($recovered['event_id']);

        $this->actingAs($fixture['accounting']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertNotContains('App\\Filament\\Pages\\PayMongoReconciliation', Filament::getPanel('admin')->getPages());
        $this->assertDatabaseHas('operational_events', ['id' => $event->id]);

        $this->actingAs($fixture['systemAdmin']);

        Livewire::test(SystemHealth::class)
            ->assertSee('Provider dashboards')
            ->assertSee('Not checked by TALA')
            ->assertDontSee('sk_test_tal96d3c_not_real')
            ->assertDontSee('whsk_tal96d3c_not_real');
    }

    /**
     * @return array{
     *     student:User,
     *     accounting:User,
     *     registrar:User,
     *     systemAdmin:User,
     *     profile:StudentProfile,
     *     term:Term,
     *     enrollment:Enrollment,
     *     assessment:Assessment,
     *     attempt:PaymentAttempt
     * }
     */
    private function paymentFixture(): array
    {
        $student = $this->user('student');
        $accounting = $this->user(User::StaffRoleAccounting);
        $registrar = $this->user(User::StaffRoleRegistrar);
        $systemAdmin = $this->user(User::StaffRoleSystemSuperAdmin);
        $profile = StudentProfile::factory()->for($student)->create();
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'pending_payment',
            'credential_user_id' => $student->id,
        ]);
        $account = TermAccount::factory()->for($enrollment)->create([
            'credential_user_id' => $student->id,
            'term_id' => $term->id,
        ]);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'version' => 1,
            'content_hash' => hash('sha256', 'tal96d3c-assessment-'.$enrollment->id),
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '5000.00',
            'discount_total' => '0.00',
            'total' => '5000.00',
            'required_downpayment' => '2000.00',
            'activated_by' => $accounting->id,
            'activated_at' => now(),
        ]);
        AssessmentObligation::query()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'code' => 'CURRENT-DUE',
            'label' => 'Current exact due',
            'amount' => '1000.00',
            'due_at' => now()->subMinute(),
            'required_for_enrollment' => true,
        ]);
        AssessmentObligation::query()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 2,
            'code' => 'FUTURE-BALANCE',
            'label' => 'Future balance',
            'amount' => '4000.00',
            'due_at' => now()->addMonth(),
            'required_for_enrollment' => false,
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'tuition',
            'amount' => '5000.00',
            'source_type' => Assessment::class,
            'source_id' => $assessment->id,
            'description' => 'TAL-96D3C tuition fixture',
            'posted_by' => $accounting->id,
            'posted_at' => now(),
            'state' => 'posted',
        ]);
        $snapshot = app(ExactDuePaymentSnapshotService::class)->forAccount($account);
        $attempt = PaymentAttempt::query()->create([
            'assessment_id' => $assessment->id,
            'term_account_id' => $account->id,
            'student_profile_id' => $profile->id,
            'assessment_version' => $assessment->version,
            'snapshot_created_at' => $snapshot['created_at'],
            'snapshot_checksum' => $snapshot['checksum'],
            'channel' => 'paymongo',
            'provider' => 'paymongo',
            'internal_reference' => 'TALA-PAY-'.fake()->unique()->uuid(),
            'provider_checkout_id' => 'cs_'.fake()->unique()->bothify('tal96d3c_########'),
            'provider_intent_id' => 'pi_'.fake()->unique()->bothify('tal96d3c_########'),
            'amount' => '1000.00',
            'currency' => 'PHP',
            'status' => PaymentAttempt::StatusPending,
        ]);
        foreach ($snapshot['obligations'] as $target) {
            $attempt->obligations()->create([
                'assessment_obligation_id' => $target['id'],
                'sequence' => $target['sequence'],
                'amount' => $target['amount'],
            ]);
        }

        return compact(
            'student',
            'accounting',
            'registrar',
            'systemAdmin',
            'profile',
            'term',
            'enrollment',
            'assessment',
            'attempt',
        );
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    private function fakeCheckoutRetrieval(PaymentAttempt $attempt, string $status = 'active', array $payments = []): void
    {
        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions/'.$attempt->provider_checkout_id => Http::response([
                'data' => [
                    'id' => $attempt->provider_checkout_id,
                    'type' => 'checkout_session',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/'.$attempt->provider_checkout_id,
                        'status' => $status,
                        'livemode' => false,
                        'reference_number' => $attempt->internal_reference,
                        'payment_intent' => [
                            'id' => $attempt->provider_intent_id,
                        ],
                        'payments' => $payments,
                    ],
                ],
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function providerPayment(PaymentAttempt $attempt, int $amount = 100000, string $status = 'paid'): array
    {
        return [
            'id' => 'pay_tal96d3c',
            'type' => 'payment',
            'attributes' => [
                'status' => $status,
                'amount' => $amount,
                'currency' => 'PHP',
                'livemode' => false,
                'payment_intent_id' => $attempt->provider_intent_id,
                'disputed' => false,
                'refunds' => [],
                'paid_at' => now()->timestamp,
            ],
        ];
    }
}
