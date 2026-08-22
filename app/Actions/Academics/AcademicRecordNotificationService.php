<?php

namespace App\Actions\Academics;

use App\Mail\AcademicRecordChangedMail;
use App\Models\GradeOutcomeEvent;
use App\Models\OperationalEvent;
use App\Models\StudentLifecycleChange;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class AcademicRecordNotificationService
{
    public function recordAfterCommit(GradeOutcomeEvent $resultEvent, string $changeLabel): ?OperationalEvent
    {
        $resultEvent->loadMissing('row.courseEnrollment.enrollment.credentialUser');
        $recipient = $resultEvent->row?->courseEnrollment?->enrollment?->credentialUser;

        if (! $recipient instanceof User) {
            return null;
        }

        $event = OperationalEvent::query()->firstOrCreate(
            [
                'event_domain' => OperationalEvent::DomainNotifications,
                'external_id' => "academic-record-event:{$resultEvent->id}:user:{$recipient->id}",
            ],
            [
                'integration' => OperationalEvent::IntegrationMail,
                'channel' => OperationalEvent::ChannelEmail,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => OperationalEvent::TypeAcademicRecordUpdatedEmail,
                'event_version' => '1',
                'user_id' => $recipient->id,
                'recipient_snapshot' => ['user_id' => $recipient->id, 'email' => $recipient->email],
                'status' => OperationalEvent::StatusPending,
                'occurred_at' => now(),
                'related_record_type' => GradeOutcomeEvent::class,
                'related_record_id' => $resultEvent->id,
                'payload' => ['change_label' => $changeLabel],
            ],
        );

        if ($event->wasRecentlyCreated) {
            DB::afterCommit(fn () => $this->queue($event->id));
        }

        return $event;
    }

    public function resend(OperationalEvent $event, User $actor): OperationalEvent
    {
        $event = OperationalEvent::query()->lockForUpdate()->findOrFail($event->id);

        if ($event->event_type !== OperationalEvent::TypeAcademicRecordUpdatedEmail
            || ! in_array($event->status, [OperationalEvent::StatusFailed, OperationalEvent::StatusPending], true)) {
            throw ValidationException::withMessages(['notification' => 'Only failed or pending academic-record mail may be resent.']);
        }

        if ((int) $event->user_id !== (int) $actor->id && ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('You are not authorized to resend this academic-record notification.');
        }

        $event->update([
            'status' => OperationalEvent::StatusPending,
            'processed_at' => null,
            'failed_at' => null,
            'diagnostics' => null,
        ]);
        $this->queue($event->id);

        return $event->fresh();
    }

    public function recordLifecycleAfterCommit(StudentLifecycleChange $change): ?OperationalEvent
    {
        $change->loadMissing('studentProfile.user');
        $recipient = $change->studentProfile?->user;

        if (! $recipient instanceof User) {
            return null;
        }

        $event = OperationalEvent::query()->firstOrCreate(
            [
                'event_domain' => OperationalEvent::DomainNotifications,
                'external_id' => "academic-lifecycle-event:{$change->id}:user:{$recipient->id}:state:{$change->state}",
            ],
            [
                'integration' => OperationalEvent::IntegrationMail,
                'channel' => OperationalEvent::ChannelEmail,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => OperationalEvent::TypeAcademicRecordUpdatedEmail,
                'event_version' => '1',
                'user_id' => $recipient->id,
                'recipient_snapshot' => ['user_id' => $recipient->id, 'email' => $recipient->email],
                'status' => OperationalEvent::StatusPending,
                'occurred_at' => now(),
                'related_record_type' => StudentLifecycleChange::class,
                'related_record_id' => $change->id,
                'payload' => ['change_label' => 'An authorized lifecycle result'],
            ],
        );

        if ($event->wasRecentlyCreated) {
            DB::afterCommit(fn () => $this->queue($event->id));
        }

        return $event;
    }

    private function queue(int $eventId): void
    {
        $event = OperationalEvent::query()->findOrFail($eventId);
        $recipient = User::query()->findOrFail($event->user_id);

        try {
            if (filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Recipient email is invalid.');
            }

            Mail::to($recipient)->queue(new AcademicRecordChangedMail(
                operationalEventId: $event->id,
                recipientName: $recipient->getFilamentName(),
                changeLabel: (string) data_get($event->payload, 'change_label', 'An academic record update'),
                actionUrl: url('/student/academics'),
            ));
        } catch (Throwable $exception) {
            $event->update([
                'status' => OperationalEvent::StatusFailed,
                'processed_at' => now(),
                'failed_at' => now(),
                'diagnostics' => ['reason' => 'Mail could not be queued.', 'exception_type' => class_basename($exception)],
            ]);
        }
    }
}
