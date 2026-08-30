<?php

namespace App\Actions\Completion;

use App\Mail\AcademicRecordChangedMail;
use App\Models\CompletionReadinessVersion;
use App\Models\DegreeConferral;
use App\Models\OperationalEvent;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class CompletionNotificationService
{
    /**
     * Reserve the single completion-action notification only when an active
     * application gains a blocker or an existing blocker materially changes.
     */
    public function reserveReadinessAfterCommit(
        CompletionReadinessVersion $readiness,
        ?CompletionReadinessVersion $previous,
    ): ?OperationalEvent {
        if ($readiness->state !== CompletionReadinessProjection::AwaitingResultsOrClearance) {
            return null;
        }

        $currentBlockers = $this->normalizedBlockers($readiness->blockers ?? []);
        $previousBlockers = $this->normalizedBlockers(
            $previous instanceof CompletionReadinessVersion ? $previous->blockers : [],
        );
        if (! $this->hasActionableDelta($currentBlockers, $previousBlockers)) {
            return null;
        }

        return $this->reserve(
            studentId: $readiness->student_profile_id,
            relatedRecord: $readiness,
            eventType: OperationalEvent::TypeCompletionRequiresActionEmail,
            externalId: "completion-readiness:{$readiness->id}:student:{$readiness->student_profile_id}",
            changeLabel: 'Completion requires action',
            context: ['readiness_version_id' => $readiness->id, 'blockers' => $currentBlockers],
        );
    }

    public function reserveConferralAfterCommit(DegreeConferral $conferral): ?OperationalEvent
    {
        return $this->reserve(
            studentId: $conferral->student_profile_id,
            relatedRecord: $conferral,
            eventType: OperationalEvent::TypeConferralRecordedEmail,
            externalId: "degree-conferral:{$conferral->id}:student:{$conferral->student_profile_id}",
            changeLabel: 'Conferral recorded',
            context: ['degree_conferral_id' => $conferral->id],
        );
    }

    /** @param array<string, mixed> $context */
    private function reserve(
        int $studentId,
        Model $relatedRecord,
        string $eventType,
        string $externalId,
        string $changeLabel,
        array $context,
    ): ?OperationalEvent {
        $student = StudentProfile::query()->with('user')->find($studentId);
        $recipient = $student?->user;
        if (! $recipient instanceof User) {
            return null;
        }

        $event = OperationalEvent::query()->firstOrCreate(
            [
                'event_domain' => OperationalEvent::DomainNotifications,
                'external_id' => $externalId,
            ],
            [
                'integration' => OperationalEvent::IntegrationMail,
                'channel' => OperationalEvent::ChannelEmail,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => $eventType,
                'event_version' => '1',
                'user_id' => $recipient->id,
                'recipient_snapshot' => ['user_id' => $recipient->id, 'email' => $recipient->email],
                'status' => OperationalEvent::StatusPending,
                'occurred_at' => now(),
                'related_record_type' => $relatedRecord->getMorphClass(),
                'related_record_id' => $relatedRecord->getKey(),
                'payload' => [
                    'change_label' => $changeLabel,
                    'action_path' => '/student/academics',
                    'context' => $context,
                    'delivery_attempts' => [],
                ],
            ],
        );

        if ($event->wasRecentlyCreated) {
            DB::afterCommit(fn () => $this->queue($event->id));
        }

        return $event;
    }

    /** @param array<int, mixed> $blockers
     * @return array<string, array{consequence: string, owner: string, recovery: string}>
     */
    private function normalizedBlockers(array $blockers): array
    {
        return collect($blockers)
            ->filter(fn (mixed $blocker): bool => is_array($blocker) && filled($blocker['code'] ?? null))
            ->mapWithKeys(fn (array $blocker): array => [(string) $blocker['code'] => [
                'consequence' => (string) ($blocker['consequence'] ?? $blocker['reason'] ?? ''),
                'owner' => (string) ($blocker['owner'] ?? ''),
                'recovery' => (string) ($blocker['recovery'] ?? ''),
            ]])
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, array{consequence: string, owner: string, recovery: string}>  $current
     * @param  array<string, array{consequence: string, owner: string, recovery: string}>  $previous
     */
    private function hasActionableDelta(array $current, array $previous): bool
    {
        foreach ($current as $code => $details) {
            if (! array_key_exists($code, $previous) || $previous[$code] !== $details) {
                return true;
            }
        }

        return false;
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
            if (filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Recipient email is invalid.');
            }

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
