<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Mail\AdmissionsTransactionalMail;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\ApplicationCorrectionRequest;
use App\Models\OperationalEvent;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdmissionTransactionalMailTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
    }

    public function test_each_authorized_admissions_trigger_queues_one_after_commit_safe_workspace_message(): void
    {
        Mail::fake();
        $application = $this->application();
        $recipient = $application->user;
        $ledger = app(AdmissionNotificationLedger::class);
        $messages = [
            ['admission_application_submitted', 'submission:1', [
                'application_reference' => $application->application_reference,
                'submitted_at' => now()->toIso8601String(),
            ]],
            ['admission_application_resubmitted', 'submission:2', [
                'application_reference' => $application->application_reference,
                'submitted_at' => now()->toIso8601String(),
            ]],
            ['admission_correction_requested', 'correction-request:1', [
                'application_reference' => $application->application_reference,
                'affected_items' => ['Current province', 'Form 138'],
                'instruction' => 'Correct only the named items.',
                'due_at' => now()->addDay()->toIso8601String(),
            ]],
            ['admission_application_admitted', 'admission-decision:1', [
                'application_reference' => $application->application_reference,
                'result' => 'Admitted',
                'applicant_explanation' => 'You are admitted, subject to official credentials.',
                'credential_instructions' => ['Submit the official Form 138 to the Registrar.'],
            ]],
            ['admission_application_not_admitted', 'admission-decision:2', [
                'application_reference' => $application->application_reference,
                'result' => 'NotAdmitted',
                'applicant_explanation' => 'The current admission review is complete.',
                'support_contact' => 'Registrar support',
            ]],
            ['admission_ready_for_enrollment', 'readiness-event:1', [
                'application_reference' => $application->application_reference,
                'ready' => true,
            ]],
            ['admission_application_withdrawn', 'withdrawal-event:1', [
                'application_reference' => $application->application_reference,
                'withdrawn' => true,
                'support_contact' => 'Registrar support',
            ]],
        ];

        foreach ($messages as [$eventType, $sourceKey, $payload]) {
            $ledger->queuePending($application, $recipient, $eventType, $sourceKey, $payload);
        }

        $ledger->queuePending($application, $recipient, ...$messages[0]);

        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');

        Mail::assertQueuedCount(count($messages));
        $expectedSubjects = [
            'admission_application_submitted' => "{$institution} — Application received",
            'admission_application_resubmitted' => "{$institution} — Application received",
            'admission_correction_requested' => "{$institution} — Action needed for your application",
            'admission_application_admitted' => "{$institution} — Admission result available",
            'admission_application_not_admitted' => "{$institution} — Admission result available",
            'admission_ready_for_enrollment' => "{$institution} — Ready to start enrollment",
            'admission_application_withdrawn' => "{$institution} — Application withdrawal recorded",
        ];

        foreach ($expectedSubjects as $eventType => $expectedSubject) {
            Mail::assertQueued(AdmissionsTransactionalMail::class, function (AdmissionsTransactionalMail $mail) use ($application, $recipient, $eventType, $expectedSubject): bool {
                return $mail->hasTo($recipient->email)
                    && $mail->operationalEventType === $eventType
                    && $mail->subjectLine === $expectedSubject
                    && $mail->afterCommit === true
                    && $mail->actionUrl === route('filament.applicant.pages.dashboard', ['application' => $application->id])
                    && ! str_contains(json_encode($mail->safeLines, JSON_THROW_ON_ERROR), 'LRN')
                    && ! str_contains(json_encode($mail->safeLines, JSON_THROW_ON_ERROR), 'evidence');
            });
        }
    }

    public function test_delivery_failure_preserves_domain_state_and_authorized_resend_is_idempotent(): void
    {
        $application = $this->application();
        $recipient = $application->user;
        $ledger = app(AdmissionNotificationLedger::class);
        $event = $ledger->recordPending(
            $application,
            $recipient,
            'admission_application_submitted',
            'submission:'.$application->id,
            [
                'application_reference' => $application->application_reference,
                'submitted_at' => now()->toIso8601String(),
            ],
        );
        $ledger->mailFor($event)->failed(new RuntimeException('smtp_password=must-not-be-persisted'));

        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        $this->assertSame(OperationalEvent::StatusFailed, $event->fresh()->status);
        $this->assertStringNotContainsString(
            'smtp_password',
            json_encode($event->fresh()->diagnostics, JSON_THROW_ON_ERROR),
        );

        Mail::fake();
        $retried = $ledger->resend($event->fresh(), $recipient);

        $this->assertSame(OperationalEvent::StatusPending, $retried->status);
        Mail::assertQueued(AdmissionsTransactionalMail::class, 1);

        $ledger->resend($retried->fresh(), $recipient);
        Mail::assertQueued(AdmissionsTransactionalMail::class, 1);
    }

    public function test_successful_transport_records_delivery_outcome(): void
    {
        $application = $this->application();
        $ledger = app(AdmissionNotificationLedger::class);
        $event = $ledger->recordPending(
            $application,
            $application->user,
            'admission_application_submitted',
            'submission:transport-'.$application->id,
            [
                'application_reference' => $application->application_reference,
                'submitted_at' => now()->toIso8601String(),
            ],
        );

        Mail::mailer('array')->to($application->user->email)->sendNow($ledger->mailFor($event));

        $event->refresh();
        $this->assertSame(OperationalEvent::StatusProcessed, $event->status);
        $this->assertNotNull($event->sent_at);
        $this->assertNotEmpty($event->payload['delivery']['transport_message_id'] ?? null);
    }

    public function test_delivered_correction_link_remains_owned_and_accessible_after_resubmission(): void
    {
        $application = $this->application();
        $application->forceFill(['application_state' => AdmissionApplication::StateActionNeeded])->save();
        $correction = ApplicationCorrectionRequest::factory()->for($application, 'application')->create();
        $ledger = app(AdmissionNotificationLedger::class);
        $event = $ledger->recordPending($application, $application->user,
            OperationalEvent::TypeAdmissionCorrectionRequested, 'correction-request:'.$correction->id,
            ['application_reference' => $application->application_reference]);
        $deliveredUrl = $ledger->mailFor($event)->actionUrl;

        $correction->forceFill(['state' => ApplicationCorrectionRequest::StateCompleted, 'completed_at' => now()])->save();
        $application->forceFill(['application_state' => AdmissionApplication::StateSubmitted])->save();

        $this->actingAs($application->user)->get($deliveredUrl)->assertOk()->assertSee($application->application_reference);
        $outsider = $this->application()->user;
        $this->actingAs($outsider)->get($deliveredUrl)->assertNotFound();
    }

    public function test_actual_after_commit_enqueue_exception_preserves_application_and_records_retryable_failure(): void
    {
        $application = $this->application();
        $ledger = app(AdmissionNotificationLedger::class);
        config(['queue.default' => 'database']);
        $enqueueAttempts = 0;
        Queue::createPayloadUsing(function () use (&$enqueueAttempts): array {
            $enqueueAttempts++;
            throw new RuntimeException('synthetic enqueue exception with confidential queue configuration');
        });

        try {
            $event = DB::transaction(function () use ($application, $ledger, &$enqueueAttempts): OperationalEvent {
                $application->forceFill(['application_state' => AdmissionApplication::StateSubmitted])->save();
                $event = $ledger->queuePending($application, $application->user,
                    OperationalEvent::TypeAdmissionApplicationSubmitted, 'enqueue-failure:'.$application->id,
                    ['application_reference' => $application->application_reference]);
                $this->assertSame(0, $enqueueAttempts);
                $this->assertSame(OperationalEvent::StatusPending, $event->fresh()->status);
                $this->assertArrayNotHasKey('queued_at', $event->fresh()->payload);

                return $event;
            });
        } finally {
            Queue::createPayloadUsing(null);
        }

        $this->assertSame(1, $enqueueAttempts);
        $this->assertSame(AdmissionApplication::StateSubmitted, $application->fresh()->application_state);
        $this->assertSame(OperationalEvent::StatusFailed, $event->fresh()->status);
        $this->assertNotNull($event->fresh()->failed_at);
        $this->assertArrayNotHasKey('queued_at', $event->fresh()->payload);
        $this->assertStringNotContainsString('confidential', json_encode($event->fresh()->diagnostics, JSON_THROW_ON_ERROR));

        Mail::fake();
        $retried = $ledger->resend($event->fresh(), $application->user);
        $this->assertSame(OperationalEvent::StatusPending, $retried->status);
        $this->assertArrayHasKey('queued_at', $retried->fresh()->payload);
        $ledger->resend($retried, $application->user);
        Mail::assertQueuedCount(1);
    }

    private function application(): AdmissionApplication
    {
        $application = AdmissionApplication::factory()->submitted()->create([
            'admission_cycle_id' => AdmissionCycle::factory()->create([
                'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            ])->id,
        ]);
        $application->user->forceFill(['status' => User::StatusActive])->save();
        $application->user->assignRole('applicant');

        return $application->refresh();
    }
}
