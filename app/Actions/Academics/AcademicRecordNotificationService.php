<?php

namespace App\Actions\Academics;

use App\Mail\AcademicRecordChangedMail;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\IncDeadlineAmendment;
use App\Models\OperationalEvent;
use App\Models\StudentLifecycleChange;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AcademicRecordNotificationService
{
    public function recordAfterCommit(GradeOutcomeEvent $resultEvent, string $changeLabel): ?OperationalEvent
    {
        $resultEvent->loadMissing('row.courseEnrollment.enrollment.credentialUser', 'row.roster.teachingAssignment');
        $student = $resultEvent->row?->courseEnrollment?->enrollment?->credentialUser;

        if (! $student instanceof User) {
            return null;
        }

        $type = match ($resultEvent->event_type) {
            GradeOutcomeEvent::TypeInitialRelease => OperationalEvent::TypeGradeRosterReleasedEmail,
            GradeOutcomeEvent::TypeIncResolution => OperationalEvent::TypeIncResolvedEmail,
            GradeOutcomeEvent::TypePostedCorrection => OperationalEvent::TypeGradeCorrectionReleasedEmail,
            default => OperationalEvent::TypeAcademicProgressLifecycleEmail,
        };
        $event = $this->recordForRecipientAfterCommit($student, $type, $resultEvent, $changeLabel, '/student/academics');

        if ($resultEvent->event_type === GradeOutcomeEvent::TypeInitialRelease && $resultEvent->result_code === 'INC') {
            $this->recordForRecipientAfterCommit($student, OperationalEvent::TypeIncReleasedEmail, $resultEvent, 'An INC result and completion deadline', '/student/academics');
            $facultyId = $resultEvent->row->roster?->teachingAssignment?->faculty_user_id;
            $faculty = is_numeric($facultyId) ? User::query()->find((int) $facultyId) : null;

            if ($faculty instanceof User) {
                $this->recordForRecipientAfterCommit($faculty, OperationalEvent::TypeIncReleasedEmail, $resultEvent, 'An INC result requiring Faculty follow-up', '/admin/faculty-grade-roster');
            }
        }

        return $event;
    }

    public function recordSubmissionRequiredAfterCommit(GradeRoster $roster): ?OperationalEvent
    {
        $roster->loadMissing('teachingAssignment');
        $faculty = User::query()->find($roster->teachingAssignment?->faculty_user_id);

        return $faculty instanceof User
            ? $this->recordForRecipientAfterCommit($faculty, OperationalEvent::TypeGradeSubmissionRequiredEmail, $roster, 'A current official roster requires final-result submission', '/admin/faculty-grade-roster', "lock:{$roster->lock_version}")
            : null;
    }

    public function recordRosterReturnedAfterCommit(GradeRoster $roster): ?OperationalEvent
    {
        $roster->loadMissing('teachingAssignment');
        $faculty = User::query()->find($roster->teachingAssignment?->faculty_user_id);

        return $faculty instanceof User
            ? $this->recordForRecipientAfterCommit($faculty, OperationalEvent::TypeGradeRosterReturnedEmail, $roster, 'Named roster rows were returned for correction', '/admin/faculty-grade-roster', "version:{$roster->current_version_number}")
            : null;
    }

    public function recordIncDeadlineAmendedAfterCommit(IncDeadlineAmendment $amendment): void
    {
        $amendment->loadMissing('incompleteEvent.row.courseEnrollment.enrollment.credentialUser', 'incompleteEvent.row.roster.teachingAssignment');
        $student = $amendment->incompleteEvent?->row?->courseEnrollment?->enrollment?->credentialUser;
        $faculty = User::query()->find($amendment->incompleteEvent?->row?->roster?->teachingAssignment?->faculty_user_id);

        foreach ([$student, $faculty] as $recipient) {
            if ($recipient instanceof User) {
                $this->recordForRecipientAfterCommit($recipient, OperationalEvent::TypeIncDeadlineAmendedEmail, $amendment, 'An authorized INC deadline amendment', $recipient->hasRole('student') ? '/student/academics' : '/admin/faculty-grade-roster');
            }
        }
    }

    public function resend(OperationalEvent $event, User $actor): OperationalEvent
    {
        return DB::transaction(function () use ($event, $actor): OperationalEvent {
            $event = OperationalEvent::query()->lockForUpdate()->findOrFail($event->id);

            if (! in_array($event->event_type, OperationalEvent::academicNotificationTypes(), true)
                || ! in_array($event->status, [OperationalEvent::StatusFailed, OperationalEvent::StatusPending], true)) {
                throw ValidationException::withMessages(['notification' => 'Only failed or pending academic notification mail may be resent.']);
            }

            if ((int) $event->user_id !== (int) $actor->id && ! $actor->hasRole(User::StaffRoleRegistrar)) {
                throw new AuthorizationException('You are not authorized to resend this academic notification.');
            }

            $event->update([
                'status' => OperationalEvent::StatusPending,
                'processed_at' => null,
                'failed_at' => null,
                'diagnostics' => null,
            ]);
            DB::afterCommit(fn () => $this->queue($event->id));

            return $event->fresh();
        }, attempts: 3);
    }

    public function recordLifecycleAfterCommit(StudentLifecycleChange $change): ?OperationalEvent
    {
        $change->loadMissing('studentProfile.user');
        $recipient = $change->studentProfile?->user;

        return $recipient instanceof User
            ? $this->recordForRecipientAfterCommit($recipient, OperationalEvent::TypeAcademicProgressLifecycleEmail, $change, 'An authorized lifecycle result', '/student/academics', "state:{$change->state}")
            : null;
    }

    private function recordForRecipientAfterCommit(
        User $recipient,
        string $eventType,
        Model $related,
        string $changeLabel,
        string $actionPath,
        ?string $scope = null,
    ): OperationalEvent {
        $externalId = implode(':', array_filter([
            'academic-notification',
            $eventType,
            $related->getMorphClass(),
            $related->getKey(),
            'user',
            $recipient->id,
            $scope,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        $event = OperationalEvent::query()->firstOrCreate(
            ['event_domain' => OperationalEvent::DomainNotifications, 'external_id' => $externalId],
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
                'related_record_type' => $related->getMorphClass(),
                'related_record_id' => $related->getKey(),
                'payload' => ['change_label' => $changeLabel, 'action_path' => $actionPath, 'delivery_attempts' => []],
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
            if (filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Recipient email is invalid.');
            }

            Mail::to($recipient)->queue(new AcademicRecordChangedMail(
                operationalEventId: $event->id,
                operationalEventType: $event->event_type,
                deliveryAttemptId: $attemptId,
                recipientName: $recipient->getFilamentName(),
                changeLabel: (string) data_get($event->payload, 'change_label', 'An academic record update'),
                actionUrl: url((string) data_get($event->payload, 'action_path', '/student/academics')),
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
