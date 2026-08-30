<?php

namespace App\Actions\Completion;

use App\Mail\AcademicRecordChangedMail;
use App\Models\GraduationApplication;
use App\Models\OperationalEvent;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class CompletionNotificationService
{
    public function recordAfterCommit(GraduationApplication $application, string $changeLabel): ?OperationalEvent
    {
        $student = StudentProfile::query()->with('user')->find($application->student_profile_id);
        $recipient = $student?->user;
        if (! $recipient instanceof User) {
            return null;
        }

        $event = OperationalEvent::query()->firstOrCreate(
            [
                'event_domain' => OperationalEvent::DomainNotifications,
                'external_id' => "completion-application:{$application->id}:{$application->state}:user:{$recipient->id}",
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
                'related_record_type' => GraduationApplication::class,
                'related_record_id' => $application->id,
                'payload' => ['change_label' => $changeLabel, 'action_path' => '/student/academics', 'delivery_attempts' => []],
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
        $attemptId = (string) Str::uuid();
        $payload = is_array($event->payload) ? $event->payload : [];
        $payload['delivery_attempts'] = [...($payload['delivery_attempts'] ?? []), [
            'attempt_id' => $attemptId,
            'status' => OperationalEvent::StatusPending,
            'queued_at' => now()->toIso8601String(),
        ]];
        $event->update(['payload' => $payload]);

        try {
            Mail::to($recipient)->queue(new AcademicRecordChangedMail(
                operationalEventId: $event->id,
                operationalEventType: $event->event_type,
                deliveryAttemptId: $attemptId,
                recipientName: $recipient->getFilamentName(),
                changeLabel: (string) data_get($event->payload, 'change_label', 'A completion action'),
                actionUrl: url('/student/academics'),
            ));
        } catch (Throwable $exception) {
            $payload = is_array($event->fresh()->payload) ? $event->fresh()->payload : [];
            $payload['delivery_attempts'] = collect($payload['delivery_attempts'] ?? [])->map(function (array $attempt) use ($attemptId): array {
                return ($attempt['attempt_id'] ?? null) === $attemptId
                    ? [...$attempt, 'status' => OperationalEvent::StatusFailed, 'failed_at' => now()->toIso8601String()]
                    : $attempt;
            })->all();
            $event->update([
                'status' => OperationalEvent::StatusFailed,
                'processed_at' => now(),
                'failed_at' => now(),
                'diagnostics' => ['reason' => 'Mail could not be queued.', 'exception_type' => class_basename($exception)],
                'payload' => $payload,
            ]);
        }
    }
}
