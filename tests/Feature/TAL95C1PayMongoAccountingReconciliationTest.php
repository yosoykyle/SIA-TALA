<?php

namespace Tests\Feature;

use App\Actions\Integrations\Payments\ExactDuePaymentSnapshotService;
use App\Actions\Integrations\Payments\PayMongoReconciliationService;
use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Jobs\ProcessPayMongoWebhookCall;
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
use Carbon\CarbonImmutable;
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

        foreach ([User::StaffRoleAccounting, User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_accounting_recovery_is_scoped_to_student_accounts_without_generic_operational_event_access(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $assessment = $this->activeAssessment($accounting);
        $attempt = $this->paymentAttempt($assessment, '1000.00', 'cs_c1_ui');
        $event = $this->eventForPayload($this->paidPayload(
            'evt_c1_ui',
            'cs_c1_ui',
            90000,
            $attempt->internal_reference,
            'PHP',
            $attempt,
        ));
        app(PayMongoWebhookProcessor::class)->process((int) data_get($event->diagnostics, 'webhook_call_id'), $event->id);
        $event->forceFill([
            'diagnostics' => [...($event->diagnostics ?? []), 'reason' => 'unknown_reference', 'private_token' => 'must-not-render'],
            'payload' => [...($event->payload ?? []), 'signature' => 'must-not-render'],
        ])->save();

        $this->actingAs($accounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(class_exists('App\\Filament\\Pages\\PayMongoReconciliation'));
        $this->assertFileDoesNotExist(app_path('Policies/OperationalEventPolicy.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/OperationalEvents/OperationalEventResource.php'));
        Livewire::test(ViewEnrollment::class, ['record' => $assessment->enrollment_id])
            ->assertActionVisible('rejectPayMongoException')
            ->assertActionHidden('confirmPayMongoException')
            ->assertDontSee('must-not-render')
            ->assertDontSee('private_token');

        $this->actingAs($registrar);
        Livewire::test(ViewEnrollment::class, ['record' => $assessment->enrollment_id])
            ->assertActionHidden('rejectPayMongoException')
            ->assertActionHidden('confirmPayMongoException');
    }

    public function test_unknown_reference_can_be_linked_and_reprocessed_from_the_original_webhook(): void
    {
        Queue::fake();
        $accounting = $this->staff(User::StaffRoleAccounting);
        $assessment = $this->activeAssessment($accounting);
        $attempt = $this->paymentAttempt($assessment, checkoutSessionId: null);
        $payload = $this->paidPayload('evt_c1_link', 'cs_c1_link', 100000, null, 'PHP', $attempt);
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
        $this->assertSame(
            $attempt->id,
            Payment::query()->where('payment_attempt_id', $attempt->id)->sole()->payment_attempt_id,
        );
    }

    public function test_amount_mismatch_remains_review_only_and_cannot_be_confirmed(): void
    {
        Mail::fake();

        $accounting = $this->staff(User::StaffRoleAccounting);
        $assessment = $this->activeAssessment($accounting);
        $attempt = $this->paymentAttempt($assessment, '1000.00', 'cs_c1_confirm');
        $event = $this->eventForPayload($this->paidPayload('evt_c1_confirm', 'cs_c1_confirm', 99900, $attempt->internal_reference, 'PHP', $attempt));
        $webhookCallId = (int) data_get($event->diagnostics, 'webhook_call_id');
        $processorResult = app(PayMongoWebhookProcessor::class)->process($webhookCallId, $event->id);

        $this->assertSame('amount_mismatch', $processorResult['reason']);

        try {
            app(PayMongoReconciliationService::class)->confirm(
                $event->id,
                'The provider amount differs from the immutable exact due.',
                $accounting,
            );
            $this->fail('Amount mismatch must not be manually converted into a posting.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be confirmed', $exception->getMessage());
        }

        $payment = Payment::query()->where('payment_attempt_id', $attempt->id)->sole();
        $this->assertSame('under_review', $payment->evidence_status);
        $this->assertSame(PaymentAttempt::StatusReviewRequired, $attempt->fresh()->status);
        $this->assertSame(OperationalEvent::StatusReviewRequired, $event->fresh()->status);
        $this->assertSame(0, LedgerEntry::query()->where('payment_id', $payment->id)->count());
        Mail::assertNothingQueued();
    }

    public function test_reject_is_terminal_idempotent_and_never_posts_the_ledger(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $assessment = $this->activeAssessment($accounting);
        $attempt = $this->paymentAttempt($assessment, '1000.00', 'cs_c1_reject');
        $event = $this->eventForPayload($this->paidPayload('evt_c1_reject', 'cs_c1_reject', 90000, $attempt->internal_reference, 'PHP', $attempt));
        app(PayMongoWebhookProcessor::class)->process((int) data_get($event->diagnostics, 'webhook_call_id'), $event->id);

        $service = app(PayMongoReconciliationService::class);
        $first = $service->reject($event->id, 'Accounting rejected the amount discrepancy.', $accounting);
        $second = $service->reject($event->id, 'Repeated rejection remains idempotent.', $accounting);

        $this->assertSame('rejected', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $payment = Payment::query()
            ->where('payment_attempt_id', $attempt->id)
            ->sole();

        $this->assertSame('rejected', $payment->evidence_status);
        $this->assertNull($payment->verified_by);
        $this->assertSame(PaymentAttempt::StatusFailed, $attempt->fresh()->status);
        $this->assertSame(0, LedgerEntry::query()
            ->where('direction', LedgerEntry::DirectionPayment)
            ->where('payment_id', $payment->id)
            ->count());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'paymongo_evidence_rejected')->count());
    }

    public function test_hard_invalid_and_refund_evidence_cannot_be_confirmed(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $assessment = $this->activeAssessment($accounting);
        $attempt = $this->paymentAttempt($assessment, '1000.00', 'cs_c1_currency');
        $event = $this->eventForPayload($this->paidPayload('evt_c1_currency', 'cs_c1_currency', 100000, $attempt->internal_reference, 'USD', $attempt));
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
        $credential = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $credential->assignRole('student');
        $student->update(['user_id' => $credential->id]);
        $enrollment = Enrollment::factory()->for($student)->for($term)->create([
            'status' => 'pending_payment',
            'credential_user_id' => $credential->id,
        ]);
        $account = TermAccount::factory()->for($enrollment)->create([
            'credential_user_id' => $credential->id,
            'term_id' => $term->id,
        ]);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'version' => 1,
            'content_hash' => hash('sha256', 'tal95c1-assessment-'.$enrollment->id),
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
            'code' => 'FUTURE-DUE',
            'label' => 'Future balance',
            'amount' => '1000.00',
            'due_at' => now()->addMonth(),
            'required_for_enrollment' => false,
        ]);

        return $assessment->setRelation('enrollment', $enrollment->setRelation('studentProfile', $student));
    }

    private function paymentAttempt(Assessment $assessment, string $amount = '1000.00', ?string $checkoutSessionId = null): PaymentAttempt
    {
        $snapshot = app(ExactDuePaymentSnapshotService::class)->forAccount($assessment->termAccount);
        $attempt = PaymentAttempt::query()->create([
            'assessment_id' => $assessment->id,
            'term_account_id' => $assessment->term_account_id,
            'student_profile_id' => $assessment->enrollment->student_profile_id,
            'assessment_version' => $assessment->version,
            'snapshot_created_at' => $snapshot['created_at'],
            'snapshot_checksum' => $snapshot['checksum'],
            'channel' => 'paymongo',
            'provider' => 'paymongo',
            'internal_reference' => 'TALA-PAY-'.Str::upper((string) Str::uuid()),
            'provider_checkout_id' => $checkoutSessionId,
            'amount' => $amount,
            'currency' => 'PHP',
            'status' => PaymentAttempt::StatusPending,
            'metadata' => [],
        ]);

        foreach ($snapshot['obligations'] as $target) {
            $attempt->obligations()->create([
                'assessment_obligation_id' => $target['id'],
                'sequence' => $target['sequence'],
                'amount' => $target['amount'],
            ]);
        }

        return $attempt;
    }

    /** @return array<string, mixed> */
    private function paidPayload(
        string $eventId,
        string $checkoutSessionId,
        int $amountCentavos,
        ?string $talaReference,
        string $currency = 'PHP',
        ?PaymentAttempt $attempt = null,
    ): array {
        $metadata = $talaReference === null ? [] : ['tala_reference' => $talaReference];
        if ($attempt instanceof PaymentAttempt) {
            $metadata = [
                ...$metadata,
                'term_account_id' => (string) $attempt->term_account_id,
                'assessment_version' => (string) $attempt->assessment_version,
                'snapshot_checksum' => (string) $attempt->snapshot_checksum,
            ];
        }

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
