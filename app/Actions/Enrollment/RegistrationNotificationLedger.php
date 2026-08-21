<?php

namespace App\Actions\Enrollment;

use App\Mail\OfficialEnrollmentMail;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegistrationNotificationLedger
{
    public function recordOfficialEnrollment(Enrollment $enrollment): OperationalEvent
    {
        $enrollment->loadMissing(['credentialUser', 'term', 'currentCorVersion']);
        $recipient = $enrollment->credentialUser;
        $event = OperationalEvent::query()->firstOrCreate(
            ['event_domain' => OperationalEvent::DomainNotifications, 'external_id' => "official-enrollment:cor:{$enrollment->current_cor_version_id}:user:{$recipient->id}"],
            [
                'integration' => OperationalEvent::IntegrationMail,
                'channel' => OperationalEvent::ChannelEmail,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => OperationalEvent::TypeOfficialEnrollmentEmail,
                'event_version' => '1',
                'user_id' => $recipient->id,
                'recipient_snapshot' => ['user_id' => $recipient->id, 'email' => $recipient->email],
                'status' => OperationalEvent::StatusPending,
                'occurred_at' => now(),
                'related_record_type' => Enrollment::class,
                'related_record_id' => $enrollment->id,
                'payload' => ['term_label' => $enrollment->term?->label, 'cor_version_id' => $enrollment->current_cor_version_id],
            ],
        );

        if ($event->wasRecentlyCreated) {
            $this->queue($event, $recipient);
        }

        return $event;
    }

    public function resend(OperationalEvent $event, User $actor): OperationalEvent
    {
        if (! $actor->canAuthenticate()) {
            abort(403);
        }

        $outcome = DB::transaction(function () use ($event, $actor): array {
            $locked = OperationalEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->event_type !== OperationalEvent::TypeOfficialEnrollmentEmail || $locked->status !== OperationalEvent::StatusFailed) {
                throw ValidationException::withMessages(['notification' => 'Only a failed official-enrollment notification may be resent.']);
            }
            if ((int) $locked->user_id !== (int) $actor->id && ! $actor->hasRole(User::StaffRoleRegistrar)) {
                abort(403);
            }
            $recipient = User::query()->findOrFail($locked->user_id);
            $locked->update(['status' => OperationalEvent::StatusPending, 'processed_at' => null, 'failed_at' => null, 'diagnostics' => null]);

            return [$locked->fresh(), $recipient];
        }, attempts: 3);
        $this->queue($outcome[0], $outcome[1]);

        return $outcome[0]->fresh();
    }

    private function queue(OperationalEvent $event, User $recipient): void
    {
        try {
            if (filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Recipient email is invalid.');
            }
            $payload = is_array($event->payload) ? $event->payload : [];
            Mail::to($recipient)->queue(new OfficialEnrollmentMail(
                operationalEventId: $event->id,
                recipientName: $recipient->getFilamentName(),
                termLabel: (string) ($payload['term_label'] ?? 'Current Term'),
                corUrl: route('cor.print', ['enrollment' => $event->related_record_id]),
            ));
        } catch (Throwable $exception) {
            $event->update(['status' => OperationalEvent::StatusFailed, 'processed_at' => now(), 'failed_at' => now(), 'diagnostics' => ['reason' => 'Mail could not be queued.', 'exception_type' => class_basename($exception)]]);
        }
    }
}
