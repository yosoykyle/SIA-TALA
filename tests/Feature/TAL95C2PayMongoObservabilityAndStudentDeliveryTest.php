<?php

namespace Tests\Feature;

use App\Actions\Finance\StudentPaymentEvidencePresenter;
use App\Actions\Integrations\Payments\PayMongoPaymentPostingService;
use App\Actions\Integrations\Payments\PayMongoWebhookProcessor;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL95C2PayMongoObservabilityAndStudentDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        foreach (['student', User::StaffRoleAccounting] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_new_ledger_posting_queues_one_student_notice_and_duplicate_posting_does_not_queue_again(): void
    {
        Mail::fake();
        $fixture = $this->paymentFixture();
        $service = app(PayMongoPaymentPostingService::class);

        $first = $service->post(
            attempt: $fixture['attempt'],
            amount: '1000.00',
            providerReference: 'pay_c2_automatic',
            actor: null,
            timestamp: CarbonImmutable::parse('2026-07-15 09:00:00'),
            description: 'PayMongo verified payment',
        );
        $second = $service->post(
            attempt: $fixture['attempt']->fresh(),
            amount: '1000.00',
            providerReference: 'pay_c2_automatic',
            actor: null,
            timestamp: CarbonImmutable::parse('2026-07-15 09:01:00'),
            description: 'PayMongo duplicate delivery',
        );

        $this->assertSame('posted', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, LedgerEntry::query()
            ->where('direction', LedgerEntry::DirectionPayment)
            ->where('payment_id', $first['payment']->id)
            ->count());
        $this->assertSame(1, OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
            ->where('related_record_id', $first['payment']->id)
            ->count());
        Mail::assertQueuedCount(1);
        Mail::assertQueued(PaymentPostedMail::class, function (PaymentPostedMail $mail) use ($fixture): bool {
            $this->assertTrue($mail->hasTo($fixture['student']->email));
            $this->assertSame('PHP 1,000.00', $mail->amount);
            $this->assertSame(route('filament.student.pages.finance'), $mail->financeUrl);

            return true;
        });

        $event = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
            ->where('related_record_id', $first['payment']->id)
            ->sole();
        $this->assertSame($fixture['student']->id, $event->user_id);
        $this->assertSame(Payment::class, $event->related_record_type);
        $this->assertSame($first['payment']->id, $event->related_record_id);
        $this->assertArrayNotHasKey('provider_reference', $event->payload ?? []);
    }

    public function test_automatic_webhook_posting_uses_the_same_student_notification_boundary(): void
    {
        Mail::fake();
        $fixture = $this->paymentFixture();
        $payload = $this->paidPayload(
            eventId: 'evt_c2_automatic',
            checkoutSessionId: 'cs_c2_automatic',
            amountCentavos: 100000,
            talaReference: $fixture['attempt']->internal_reference,
        );
        $event = $this->eventForPayload($payload);

        $result = app(PayMongoWebhookProcessor::class)->process(
            (int) data_get($event->diagnostics, 'webhook_call_id'),
            $event->id,
        );

        $this->assertSame('posted', $result['status']);
        $this->assertSame(OperationalEvent::StatusProcessed, $event->fresh()->status);
        $payment = Payment::query()
            ->where('payment_attempt_id', $fixture['attempt']->id)
            ->sole();
        $this->assertSame(1, LedgerEntry::query()
            ->where('direction', LedgerEntry::DirectionPayment)
            ->where('payment_id', $payment->id)
            ->count());
        Mail::assertQueued(PaymentPostedMail::class, 1);
    }

    public function test_invalid_recipient_and_mail_transport_failure_do_not_reverse_financial_posting(): void
    {
        Mail::fake();
        $fixture = $this->paymentFixture(['email' => 'not-an-email']);

        $result = app(PayMongoPaymentPostingService::class)->post(
            attempt: $fixture['attempt'],
            amount: '1000.00',
            providerReference: 'pay_c2_invalid_email',
            actor: $fixture['accounting'],
            timestamp: CarbonImmutable::parse('2026-07-15 10:00:00'),
            description: 'Accounting-confirmed PayMongo payment',
        );

        $event = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
            ->where('related_record_id', $result['payment']->id)
            ->sole();
        $this->assertSame(OperationalEvent::StatusFailed, $event->status);
        $this->assertSame('Recipient email is missing or invalid.', data_get($event->diagnostics, 'reason'));
        $this->assertDatabaseHas('payments', ['id' => $result['payment']->id, 'evidence_status' => 'verified']);
        $this->assertDatabaseHas('ledger_entries', ['id' => $result['ledger_entry']->id, 'state' => 'posted']);
        Mail::assertNothingQueued();

        $validFixture = $this->paymentFixture();
        app(PayMongoPaymentPostingService::class)->post(
            attempt: $validFixture['attempt'],
            amount: '1000.00',
            providerReference: 'pay_c2_transport_failure',
            actor: null,
            timestamp: CarbonImmutable::parse('2026-07-15 10:30:00'),
            description: 'PayMongo verified payment',
        );

        $queuedMail = null;
        Mail::assertQueued(PaymentPostedMail::class, function (PaymentPostedMail $mail) use (&$queuedMail): bool {
            $queuedMail = $mail;

            return true;
        });
        if (! $queuedMail instanceof PaymentPostedMail) {
            $this->fail('The payment-posted mail should have been queued.');
        }

        $queuedMail->failed(new RuntimeException('Sensitive transport details'));

        $failedEvent = OperationalEvent::query()->findOrFail($queuedMail->operationalEventId);
        $this->assertSame(OperationalEvent::StatusFailed, $failedEvent->status);
        $this->assertSame('Mail delivery failed.', data_get($failedEvent->diagnostics, 'reason'));
        $this->assertStringNotContainsString('Sensitive transport details', json_encode($failedEvent->diagnostics, JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('payments', ['id' => $failedEvent->related_record_id, 'evidence_status' => 'verified']);
        $this->assertDatabaseHas('ledger_entries', ['payment_id' => $failedEvent->related_record_id, 'state' => 'posted']);
    }

    public function test_mail_transport_acceptance_records_allowlisted_delivery_evidence(): void
    {
        $recipient = User::factory()->create();
        $event = OperationalEvent::factory()->forUser($recipient)->create([
            'event_domain' => OperationalEvent::DomainNotifications,
            'integration' => OperationalEvent::IntegrationMail,
            'channel' => OperationalEvent::ChannelEmail,
            'direction' => OperationalEvent::DirectionOutbound,
            'event_type' => OperationalEvent::TypePaymentPostedEmail,
            'status' => OperationalEvent::StatusPending,
            'processed_at' => null,
            'sent_at' => null,
            'payload' => ['amount' => '1000.00', 'currency' => 'PHP'],
        ]);

        Mail::mailer('array')->to($recipient->email)->sendNow(new PaymentPostedMail(
            operationalEventId: (int) $event->id,
            recipientName: (string) $recipient->name,
            amount: 'PHP 1,000.00',
            termLabel: 'First Semester',
            financeUrl: route('filament.student.pages.finance'),
        ));

        $event->refresh();
        $this->assertSame(OperationalEvent::StatusProcessed, $event->status);
        $this->assertNotNull($event->processed_at);
        $this->assertNotNull($event->sent_at);
        $this->assertArrayHasKey('transport_message_id', data_get($event->payload, 'delivery'));
        $this->assertArrayHasKey('accepted_at', data_get($event->payload, 'delivery'));
    }

    public function test_transaction_rollback_removes_payment_ledger_notification_evidence_and_queued_mail(): void
    {
        Mail::fake();
        $fixture = $this->paymentFixture();
        $eventName = 'eloquent.created: '.OperationalEvent::class;

        Event::listen($eventName, function (OperationalEvent $event): void {
            if ($event->event_type === OperationalEvent::TypePaymentPostedEmail) {
                throw new RuntimeException('Forced payment posting rollback.');
            }
        });

        $paymentCount = Payment::query()->count();
        $paymentLedgerEntryCount = LedgerEntry::query()
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count();
        $notificationEventCount = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
            ->count();

        try {
            DB::transaction(fn (): array => app(PayMongoPaymentPostingService::class)->post(
                attempt: $fixture['attempt'],
                amount: '1000.00',
                providerReference: 'pay_c2_rollback',
                actor: null,
                timestamp: CarbonImmutable::parse('2026-07-15 11:00:00'),
                description: 'PayMongo verified payment',
            ));
            $this->fail('Expected the payment posting transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced payment posting rollback.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame($paymentLedgerEntryCount, LedgerEntry::query()
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
        $this->assertSame($notificationEventCount, OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
            ->count());
        Mail::assertNothingQueued();
    }

    #[DataProvider('attemptEvidenceStates')]
    public function test_student_payment_evidence_uses_allowlisted_guidance_for_each_attempt_state(
        ?string $attemptStatus,
        string $headline,
        string $requiredAction,
    ): void {
        $attempts = collect();

        if ($attemptStatus !== null) {
            $attempts->push((new PaymentAttempt)->forceFill(['status' => $attemptStatus]));
        }

        $evidence = app(StudentPaymentEvidencePresenter::class)->present($attempts, collect(), collect());

        $this->assertSame($headline, $evidence['headline']);
        $this->assertStringContainsString($requiredAction, $evidence['required_action']);
        $this->assertSame('Accounting', $evidence['responsible_office']);
        $this->assertSame('Not posted', $evidence['ledger_state']);
        $this->assertSame('Not applicable', $evidence['or_mapping_state']);
    }

    public function test_partial_posting_with_a_current_due_requests_only_the_remaining_payment(): void
    {
        $postedPayment = (new Payment)->forceFill(['or_number' => null]);

        $evidence = app(StudentPaymentEvidencePresenter::class)->present(
            collect(),
            collect([$postedPayment]),
            collect([$postedPayment]),
            hasCurrentDue: true,
        );

        $this->assertSame('Payment Partially Posted', $evidence['headline']);
        $this->assertStringContainsString('Pay Current Due', $evidence['required_action']);
        $this->assertStringContainsString('remaining amount', $evidence['required_action']);
        $this->assertStringContainsString('OR mapping', $evidence['required_action']);
    }

    /** @return iterable<string, array{0:string|null,1:string,2:string}> */
    public static function attemptEvidenceStates(): iterable
    {
        yield 'no attempt' => [null, 'No Payment Submitted', 'Pay Current Due'];
        yield 'pending' => ['pending', 'Payment Pending', 'Wait for payment confirmation'];
        yield 'under review' => ['under_review', 'Payment Under Review', 'Do not submit another payment'];
        yield 'failed' => ['failed', 'Payment Rejected', 'Start a new checkout'];
        yield 'expired' => ['expired', 'Checkout Closed', 'Start a new checkout'];
        yield 'cancelled' => ['cancelled', 'Checkout Closed', 'Start a new checkout'];
    }

    /**
     * @param  array<string, mixed>  $studentOverrides
     * @return array{student:User,accounting:User,attempt:PaymentAttempt}
     */
    private function paymentFixture(array $studentOverrides = []): array
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
            ...$studentOverrides,
        ]);
        $student->assignRole('student');
        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);
        $profile = StudentProfile::factory()->for($student)->create();
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create(['status' => 'pending_payment']);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '1000.00',
            'discount_total' => '0.00',
            'total' => '1000.00',
            'required_downpayment' => '1000.00',
            'activated_by' => $accounting->id,
            'activated_at' => now(),
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'assessment',
            'amount' => '1000.00',
            'source_type' => Assessment::class,
            'source_id' => $assessment->id,
            'description' => 'Active assessment charge',
            'posted_by' => $accounting->id,
            'posted_at' => now(),
            'state' => 'posted',
        ]);
        $attempt = PaymentAttempt::query()->create([
            'assessment_id' => $assessment->id,
            'student_profile_id' => $profile->id,
            'channel' => 'paymongo',
            'provider' => 'paymongo',
            'internal_reference' => 'TALA-PAY-'.Str::upper((string) Str::uuid()),
            'amount' => '1000.00',
            'currency' => 'PHP',
            'status' => 'pending',
            'metadata' => [],
        ]);

        return compact('student', 'accounting', 'attempt');
    }

    /** @return array<string, mixed> */
    private function paidPayload(string $eventId, string $checkoutSessionId, int $amountCentavos, string $talaReference): array
    {
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
                            'currency' => 'PHP',
                            'metadata' => ['tala_reference' => $talaReference],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function eventForPayload(array $payload): OperationalEvent
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $now = CarbonImmutable::now(config('app.timezone'))->toDateTimeString();
        $webhookCallId = (int) DB::table('webhook_calls')->insertGetId([
            'name' => 'paymongo',
            'url' => 'https://tala.test/api/webhooks/paymongo',
            'headers' => json_encode(['paymongo-signature' => '[REDACTED]'], JSON_UNESCAPED_SLASHES),
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
