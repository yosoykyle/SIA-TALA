<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\PayMongoReconciliationService;
use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Filament\Pages\PayMongoReconciliation;
use App\Jobs\ProcessPayMongoWebhookCall;
use App\Mail\PaymentPostedMail;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Policies\OperationalEventPolicy;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL95C1PayMongoAccountingReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleAccounting, User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_accounting_page_is_scoped_without_expanding_generic_operational_event_access(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $event = $this->eventForPayload($this->paidPayload('evt_c1_ui', 'cs_c1_ui', 100000, 'TALA-PAY-UNKNOWN'));
        $event->forceFill([
            'status' => OperationalEvent::StatusReviewRequired,
            'diagnostics' => [...($event->diagnostics ?? []), 'reason' => 'unknown_reference', 'private_token' => 'must-not-render'],
            'payload' => [...($event->payload ?? []), 'signature' => 'must-not-render'],
        ])->save();

        $this->actingAs($accounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(PayMongoReconciliation::canAccess());
        $this->assertFalse(app(OperationalEventPolicy::class)->viewAny($accounting));
        Livewire::test(PayMongoReconciliation::class)
            ->assertCanSeeTableRecords([$event])
            ->assertSee('Unknown Reference')
            ->assertDontSee('must-not-render')
            ->assertActionVisible(TestAction::make('linkAndReprocess')->table($event))
            ->assertActionHidden(TestAction::make('confirm')->table($event));

        $this->actingAs($registrar);
        $this->assertFalse(PayMongoReconciliation::canAccess());
    }

    public function test_unknown_reference_can_be_linked_and_reprocessed_from_the_original_webhook(): void
    {
        Queue::fake();
        $accounting = $this->staff(User::StaffRoleAccounting);
        $assessment = $this->activeAssessment($accounting);
        $attempt = $this->paymentAttempt($assessment, checkoutSessionId: null);
        $payload = $this->paidPayload('evt_c1_link', 'cs_c1_link', 100000, null);
        $event = $this->eventForPayload($payload);
        $webhookCallId = (int) data_get($event->diagnostics, 'webhook_call_id');

        $initial = app(PayMongoWebhookProcessor::class)->process($webhookCallId, $event->id);
        $this->assertSame('unknown_reference', $initial['reason']);

        $result = app(PayMongoReconciliationService::class)->linkAndReprocess(
            $event->id,
            $attempt->id,
            'Matched against the student assessment reference.',
            $accounting,
        );

        $this->assertSame('requeued', $result['status']);
        $this->assertSame($attempt->id, $event->fresh()->related_record_id);
        $this->assertSame(PaymentAttempt::class, $event->fresh()->related_record_type);
        $this->assertSame($accounting->id, $event->fresh()->user_id);
        Queue::assertPushed(ProcessPayMongoWebhookCall::class, fn (ProcessPayMongoWebhookCall $job): bool => $job->webhookCallId === $webhookCallId && $job->operationalEventId === $event->id);

        $processed = app(PayMongoWebhookProcessor::class)->process($webhookCallId, $event->id);

        $this->assertSame('review_required', $processed['status']);
        $this->assertSame('missing_tala_reference', $processed['reason']);
        $this->assertSame($attempt->id, Payment::query()->sole()->payment_attempt_id);
    }

    public function test_accounting_can_confirm_bounded_amount_ambiguity_exactly_once(): void
    {
        Mail::fake();

        $accounting = $this->staff(User::StaffRoleAccounting);
        $assessment = $this->activeAssessment($accounting);
        $attempt = $this->paymentAttempt($assessment, '1000.00', 'cs_c1_confirm');
        $event = $this->eventForPayload($this->paidPayload('evt_c1_confirm', 'cs_c1_confirm', 99900, $attempt->internal_reference));
        $webhookCallId = (int) data_get($event->diagnostics, 'webhook_call_id');
        $processorResult = app(PayMongoWebhookProcessor::class)->process($webhookCallId, $event->id);

        $this->assertSame('amount_mismatch', $processorResult['reason']);

        $service = app(PayMongoReconciliationService::class);
        $first = $service->confirm($event->id, 'Provider evidence confirms the actual paid amount.', $accounting);
        $second = $service->confirm($event->id, 'Repeated confirmation remains idempotent.', $accounting);
        $payment = Payment::query()->sole();

        $this->assertSame('confirmed', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertFalse($first['finance_cleared']);
        $this->assertSame('999.00', (string) $payment->amount);
        $this->assertSame('verified', $payment->evidence_status);
        $this->assertSame($accounting->id, $payment->verified_by);
        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame('pending_payment', $assessment->enrollment->fresh()->status);
        $this->assertSame(OperationalEvent::StatusProcessed, $event->fresh()->status);
        $this->assertSame(1, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'paymongo_payment_confirmed')->count());
        Mail::assertQueued(PaymentPostedMail::class, 1);
    }

    public function test_reject_is_terminal_idempotent_and_never_posts_the_ledger(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $assessment = $this->activeAssessment($accounting);
        $attempt = $this->paymentAttempt($assessment, '1000.00', 'cs_c1_reject');
        $event = $this->eventForPayload($this->paidPayload('evt_c1_reject', 'cs_c1_reject', 90000, $attempt->internal_reference));
        app(PayMongoWebhookProcessor::class)->process((int) data_get($event->diagnostics, 'webhook_call_id'), $event->id);

        $service = app(PayMongoReconciliationService::class);
        $first = $service->reject($event->id, 'Accounting rejected the amount discrepancy.', $accounting);
        $second = $service->reject($event->id, 'Repeated rejection remains idempotent.', $accounting);

        $this->assertSame('rejected', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame('rejected', Payment::query()->sole()->evidence_status);
        $this->assertNull(Payment::query()->sole()->verified_by);
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertSame(0, LedgerEntry::query()->where('direction', LedgerEntry::DirectionPayment)->count());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'paymongo_evidence_rejected')->count());
    }

    public function test_hard_invalid_and_refund_evidence_cannot_be_confirmed(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $assessment = $this->activeAssessment($accounting);
        $attempt = $this->paymentAttempt($assessment, '1000.00', 'cs_c1_currency');
        $event = $this->eventForPayload($this->paidPayload('evt_c1_currency', 'cs_c1_currency', 100000, $attempt->internal_reference, 'USD'));
        app(PayMongoWebhookProcessor::class)->process((int) data_get($event->diagnostics, 'webhook_call_id'), $event->id);

        try {
            app(PayMongoReconciliationService::class)->confirm($event->id, 'Attempting a forbidden currency override.', $accounting);
            $this->fail('Currency mismatch must not be confirmable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be confirmed', $exception->getMessage());
        }

        $refundEvent = $this->eventForPayload($this->refundPayload('evt_c1_refund'));
        $refundEvent->forceFill([
            'status' => OperationalEvent::StatusReviewRequired,
            'diagnostics' => [...($refundEvent->diagnostics ?? []), 'reason' => 'refund_or_reversal'],
        ])->save();

        try {
            app(PayMongoReconciliationService::class)->reject($refundEvent->id, 'Refund evidence requires the adjustment workflow.', $accounting);
            $this->fail('Refund evidence must remain outside payment rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be rejected', $exception->getMessage());
        }

        $this->expectException(AuthorizationException::class);
        app(PayMongoReconciliationService::class)->reject($refundEvent->id, 'Registrar must not decide payment evidence.', $registrar);
    }

    public function test_failed_processing_can_only_requeue_the_persisted_call_with_a_reason(): void
    {
        Queue::fake();
        $accounting = $this->staff(User::StaffRoleAccounting);
        $event = $this->eventForPayload($this->paidPayload('evt_c1_retry', 'cs_c1_retry', 100000, 'TALA-PAY-UNKNOWN'));
        $event->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'failed_at' => now(),
            'diagnostics' => [...($event->diagnostics ?? []), 'reason' => 'processing_failed'],
        ])->save();

        try {
            app(PayMongoReconciliationService::class)->reprocess($event->id, ' ', $accounting);
            $this->fail('Reprocessing must require an Accounting reason.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('reason', $exception->getMessage());
        }

        $result = app(PayMongoReconciliationService::class)->reprocess(
            $event->id,
            'Retry after the transient processing failure was reviewed.',
            $accounting,
        );

        $this->assertSame('requeued', $result['status']);
        $this->assertSame(OperationalEvent::StatusPending, $event->fresh()->status);
        $this->assertSame($accounting->id, $event->fresh()->user_id);
        Queue::assertPushed(ProcessPayMongoWebhookCall::class);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'paymongo_event_requeued')->count());
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function activeAssessment(User $accounting): Assessment
    {
        $term = Term::factory()->create();
        $student = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()->for($student)->for($term)->create(['status' => 'pending_payment']);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '2000.00',
            'discount_total' => '0.00',
            'total' => '2000.00',
            'required_downpayment' => '1000.00',
            'activated_by' => $accounting->id,
            'activated_at' => now(),
        ]);

        LedgerEntry::query()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'assessment',
            'amount' => '2000.00',
            'source_type' => Assessment::class,
            'source_id' => $assessment->id,
            'description' => 'Active assessment charge',
            'posted_by' => $accounting->id,
            'posted_at' => now(),
            'state' => 'posted',
        ]);

        return $assessment->setRelation('enrollment', $enrollment->setRelation('studentProfile', $student));
    }

    private function paymentAttempt(Assessment $assessment, string $amount = '1000.00', ?string $checkoutSessionId = null): PaymentAttempt
    {
        return PaymentAttempt::query()->create([
            'assessment_id' => $assessment->id,
            'student_profile_id' => $assessment->enrollment->student_profile_id,
            'channel' => 'paymongo',
            'provider' => 'paymongo',
            'internal_reference' => 'TALA-PAY-'.Str::upper((string) Str::uuid()),
            'provider_checkout_id' => $checkoutSessionId,
            'amount' => $amount,
            'currency' => 'PHP',
            'status' => 'pending',
            'metadata' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function paidPayload(string $eventId, string $checkoutSessionId, int $amountCentavos, ?string $talaReference, string $currency = 'PHP'): array
    {
        $metadata = $talaReference === null ? [] : ['tala_reference' => $talaReference];

        return [
            'data' => [
                'id' => $eventId,
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'livemode' => false,
                    'data' => [
                        'id' => $checkoutSessionId,
                        'type' => 'checkout_session',
                        'attributes' => [
                            'status' => 'paid',
                            'amount_paid' => $amountCentavos,
                            'currency' => $currency,
                            'metadata' => $metadata,
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function refundPayload(string $eventId): array
    {
        $payload = $this->paidPayload($eventId, 'pay_c1_refund', 100000, 'TALA-PAY-REFUND');
        data_set($payload, 'data.attributes.type', 'payment.refunded');
        data_set($payload, 'data.attributes.data.type', 'payment');
        data_set($payload, 'data.attributes.data.attributes.status', 'refunded');

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function eventForPayload(array $payload): OperationalEvent
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $now = CarbonImmutable::now(config('app.timezone'))->toDateTimeString();
        $webhookCallId = (int) DB::table('webhook_calls')->insertGetId([
            'name' => 'paymongo',
            'url' => 'https://tala.test/api/webhooks/paymongo',
            'headers' => json_encode(['paymongo-signature' => 'private'], JSON_UNESCAPED_SLASHES),
            'payload' => $raw,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationPayMongo,
            'channel' => OperationalEvent::ChannelWebhook,
            'direction' => OperationalEvent::DirectionInbound,
            'event_type' => (string) data_get($payload, 'data.attributes.type'),
            'event_version' => 'v1',
            'external_id' => (string) data_get($payload, 'data.id'),
            'status' => OperationalEvent::StatusPending,
            'occurred_at' => $now,
            'diagnostics' => [
                'payload_sha256' => hash('sha256', $raw),
                'webhook_call_id' => $webhookCallId,
            ],
            'payload' => [
                'event_id' => (string) data_get($payload, 'data.id'),
                'event_type' => (string) data_get($payload, 'data.attributes.type'),
                'livemode' => false,
            ],
        ]);
    }
}
