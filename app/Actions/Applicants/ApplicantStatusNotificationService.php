<?php

namespace App\Actions\Applicants;

use App\Mail\ApplicantStatusChangedMail;
use App\Models\ApplicantIntake;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class ApplicantStatusNotificationService
{
    public function record(ApplicantIntake $intake): void
    {
        $intake->loadMissing('user');
        $recipient = $intake->user;

        if (! $recipient instanceof User) {
            return;
        }

        $notification = match ($intake->status) {
            ApplicantIntake::StatusActionRequired => [
                'event_type' => OperationalEvent::TypeApplicantActionRequiredEmail,
                'status_label' => 'Action Required',
                'guidance' => 'The Registrar found a requirement that needs correction. Open your Requirements page to review the feedback and upload a replacement where requested.',
                'next_action' => 'Review the Registrar instruction and replace each rejected digital item from Requirements.',
                'action_url' => route('filament.applicant.pages.requirements'),
                'transition_at' => $intake->reviewed_at,
            ],
            ApplicantIntake::StatusApproved => [
                'event_type' => OperationalEvent::TypeApplicantApprovedEmail,
                'status_label' => 'Approved for Handover',
                'guidance' => 'The Registrar approved your application for handover to the student-record process. Monitor your Applicant Dashboard for the next recorded step.',
                'next_action' => 'Wait for the Registrar to complete handover, then sign in to Student Hub with your activated student access.',
                'action_url' => route('filament.applicant.pages.dashboard'),
                'transition_at' => $intake->approved_at,
            ],
            default => null,
        };

        if (! is_array($notification)) {
            return;
        }

        $program = Program::query()->find($intake->program_id);
        $term = Term::query()->find($intake->term_id);
        $programLabel = $program instanceof Program ? $program->name : 'Not assigned';
        $termLabel = $term instanceof Term ? $term->label : 'Not assigned';
        $transitionAt = $notification['transition_at'] ?? now();
        $transitionKey = hash('sha256', (string) $transitionAt);
        $deliveryEvent = OperationalEvent::query()->firstOrCreate(
            [
                'event_domain' => OperationalEvent::DomainNotifications,
                'external_id' => "applicant-status:{$intake->status}:intake:{$intake->id}:at:{$transitionKey}",
            ],
            [
                'integration' => OperationalEvent::IntegrationMail,
                'channel' => OperationalEvent::ChannelEmail,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => $notification['event_type'],
                'event_version' => '1',
                'user_id' => $recipient->id,
                'recipient_snapshot' => [
                    'user_id' => (int) $recipient->id,
                    'email' => (string) $recipient->email,
                ],
                'status' => OperationalEvent::StatusPending,
                'occurred_at' => $transitionAt,
                'processed_at' => null,
                'sent_at' => null,
                'failed_at' => null,
                'related_record_type' => ApplicantIntake::class,
                'related_record_id' => (int) $intake->id,
                'diagnostics' => null,
                'payload' => [
                    'status' => $intake->status,
                    'status_label' => $notification['status_label'],
                    'program_label' => $programLabel,
                    'term_label' => $termLabel,
                    'responsible_office' => 'Registrar',
                    'next_action' => $notification['next_action'],
                ],
            ],
        );

        if (! $deliveryEvent->wasRecentlyCreated) {
            return;
        }

        $email = trim((string) $recipient->email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->markFailed($deliveryEvent, 'Recipient email is missing or invalid.');

            return;
        }

        try {
            Mail::to($recipient)->queue(new ApplicantStatusChangedMail(
                operationalEventId: (int) $deliveryEvent->id,
                applicantIntakeId: (int) $intake->id,
                recipientName: (string) $recipient->name,
                status: (string) $intake->status,
                statusLabel: (string) $notification['status_label'],
                guidance: (string) $notification['guidance'],
                actionUrl: (string) $notification['action_url'],
                operationalEventType: (string) $notification['event_type'],
                programLabel: $programLabel,
                termLabel: $termLabel,
                responsibleOffice: 'Registrar',
                nextAction: (string) $notification['next_action'],
            ));
        } catch (Throwable $exception) {
            $this->markFailed($deliveryEvent, 'Mail could not be queued.', $exception);
        }
    }

    private function markFailed(
        OperationalEvent $deliveryEvent,
        string $reason,
        ?Throwable $exception = null,
    ): void {
        $timestamp = now();
        $diagnostics = ['reason' => $reason];

        if ($exception instanceof Throwable) {
            $diagnostics['exception_type'] = class_basename($exception);
        }

        $deliveryEvent->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'processed_at' => $timestamp,
            'sent_at' => null,
            'failed_at' => $timestamp,
            'diagnostics' => $diagnostics,
        ])->save();
    }
}
