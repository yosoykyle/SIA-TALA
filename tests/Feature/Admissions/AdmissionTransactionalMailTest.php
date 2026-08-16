<?php

namespace Tests\Feature\Admissions;

use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Mail\AdmissionsTransactionalMail;
use App\Models\AdmissionApplication;
use App\Models\OperationalEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

        Mail::assertQueuedCount(count($messages));
        Mail::assertQueued(AdmissionsTransactionalMail::class, function (AdmissionsTransactionalMail $mail) use ($recipient): bool {
            return $mail->hasTo($recipient->email)
                && $mail->afterCommit === true
                && str_starts_with($mail->actionUrl, url('/applicant'))
                && ! str_contains(json_encode($mail->safeLines, JSON_THROW_ON_ERROR), 'LRN')
                && ! str_contains(json_encode($mail->safeLines, JSON_THROW_ON_ERROR), 'evidence');
        });
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

    private function application(): AdmissionApplication
    {
        $application = AdmissionApplication::factory()->submitted()->create();
        $application->user->forceFill(['status' => User::StatusActive])->save();
        $application->user->assignRole('applicant');

        return $application->refresh();
    }
}
