<?php

namespace App\Actions\Admissions;

use App\Mail\AdmissionsTransactionalMail;
use App\Models\AdmissionApplication;
use App\Models\OperationalEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdmissionNotificationLedger
{
    /** @param array<string, mixed> $safePayload */
    public function queuePending(
        AdmissionApplication $application,
        User $recipient,
        string $eventType,
        string $sourceKey,
        array $safePayload,
    ): OperationalEvent {
        $event = $this->recordPending($application, $recipient, $eventType, $sourceKey, $safePayload);

        return $this->queueRecorded($event);
    }

    /** @param array<string, mixed> $safePayload */
    public function recordPending(
        AdmissionApplication $application,
        User $recipient,
        string $eventType,
        string $sourceKey,
        array $safePayload,
    ): OperationalEvent {
        $validated = Validator::make([
            'event_type' => trim($eventType),
            'source_key' => trim($sourceKey),
        ], [
            'event_type' => ['required', 'string', 'max:120'],
            'source_key' => ['required', 'string', 'max:100'],
        ])->validate();
        $externalId = 'admissions:'.$validated['event_type'].':'.$validated['source_key'];

        return OperationalEvent::query()->firstOrCreate(
            [
                'event_domain' => OperationalEvent::DomainNotifications,
                'external_id' => $externalId,
            ],
            [
                'integration' => OperationalEvent::IntegrationMail,
                'channel' => OperationalEvent::ChannelEmail,
                'direction' => OperationalEvent::DirectionOutbound,
                'event_type' => $validated['event_type'],
                'event_version' => '1',
                'user_id' => $recipient->id,
                'recipient_snapshot' => [
                    'user_id' => $recipient->id,
                    'email' => $recipient->email,
                ],
                'status' => OperationalEvent::StatusPending,
                'occurred_at' => now(config('app.timezone')),
                'processed_at' => null,
                'sent_at' => null,
                'failed_at' => null,
                'related_record_type' => AdmissionApplication::class,
                'related_record_id' => $application->id,
                'diagnostics' => null,
                'payload' => $safePayload,
            ],
        );
    }

    public function claimForDispatch(OperationalEvent $event): bool
    {
        return OperationalEvent::query()
            ->whereKey($event->id)
            ->where('status', OperationalEvent::StatusPending)
            ->update([
                'status' => OperationalEvent::StatusProcessed,
                'processed_at' => now(config('app.timezone')),
            ]) === 1;
    }

    public function markFailed(OperationalEvent $event, string $reason): OperationalEvent
    {
        $reason = Validator::make(
            ['reason' => trim($reason)],
            ['reason' => ['required', 'string', 'max:1000']],
        )->validate()['reason'];

        return DB::transaction(function () use ($event, $reason): OperationalEvent {
            $locked = OperationalEvent::query()->lockForUpdate()->findOrFail($event->id);

            if ($locked->event_domain !== OperationalEvent::DomainNotifications
                || $locked->related_record_type !== AdmissionApplication::class) {
                throw ValidationException::withMessages([
                    'event' => 'Only an admissions notification can be marked failed here.',
                ]);
            }

            $locked->forceFill([
                'status' => OperationalEvent::StatusFailed,
                'processed_at' => now(config('app.timezone')),
                'sent_at' => null,
                'failed_at' => now(config('app.timezone')),
                'diagnostics' => ['reason' => $reason],
            ])->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    public function authorizeRetry(OperationalEvent $event, User $actor): OperationalEvent
    {
        return DB::transaction(function () use ($event, $actor): OperationalEvent {
            $locked = OperationalEvent::query()->lockForUpdate()->findOrFail($event->id);
            $this->assertSupportedEvent($locked);
            $application = AdmissionApplication::query()->findOrFail($locked->related_record_id);

            if ($locked->user_id !== $application->user_id) {
                throw ValidationException::withMessages([
                    'event' => 'The admissions notification recipient no longer matches the bound Application.',
                ]);
            }
            $authorized = ($application->user_id === $actor->id
                    && $actor->hasRole('applicant')
                    && $actor->canAuthenticate())
                || ($actor->hasRole(User::StaffRoleRegistrar)
                    && $actor->canAuthenticate()
                    && $actor->can('approve-documents'));

            if (! $authorized) {
                throw new AuthorizationException('You are not authorized to retry this admissions notification.');
            }

            if ($locked->status !== OperationalEvent::StatusFailed) {
                throw ValidationException::withMessages([
                    'status' => 'Only a failed admissions notification can be retried.',
                ]);
            }

            $payload = is_array($locked->payload) ? $locked->payload : [];
            unset($payload['queued_at'], $payload['delivery']);
            $payload['retry_authorized_at'] = now(config('app.timezone'))->toIso8601String();
            $payload['retry_authorized_by'] = $actor->id;
            $locked->forceFill([
                'status' => OperationalEvent::StatusPending,
                'processed_at' => null,
                'sent_at' => null,
                'failed_at' => null,
                'diagnostics' => null,
                'payload' => $payload,
            ])->save();

            return $locked->refresh();
        }, attempts: 3);
    }

    public function resend(OperationalEvent $event, User $actor): OperationalEvent
    {
        $fresh = OperationalEvent::query()->findOrFail($event->id);
        $this->authorizeActor($fresh, $actor);

        if ($fresh->status === OperationalEvent::StatusFailed) {
            $fresh = $this->authorizeRetry($fresh, $actor);
        } elseif ($fresh->status !== OperationalEvent::StatusPending) {
            throw ValidationException::withMessages([
                'status' => 'Only a failed or still-pending admissions message can be resent.',
            ]);
        }

        return $this->queueRecorded($fresh);
    }

    public function mailFor(OperationalEvent $event): AdmissionsTransactionalMail
    {
        $event = OperationalEvent::query()->findOrFail($event->id);

        if ($event->event_domain !== OperationalEvent::DomainNotifications
            || $event->related_record_type !== AdmissionApplication::class
            || ! in_array($event->event_type, $this->admissionsEventTypes(), true)) {
            throw ValidationException::withMessages([
                'event' => 'Only a supported admissions notification can be dispatched here.',
            ]);
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $reference = $this->payloadString($payload, 'application_reference', 'Your application');
        [$subject, $heading, $lines, $actionLabel, $path] = match ($event->event_type) {
            OperationalEvent::TypeAdmissionApplicationSubmitted,
            OperationalEvent::TypeAdmissionApplicationResubmitted => [
                'Your TALA application was received',
                $event->event_type === OperationalEvent::TypeAdmissionApplicationSubmitted
                    ? 'Application received'
                    : 'Corrected application received',
                [
                    "Application reference: {$reference}",
                    'Received: '.$this->payloadString($payload, 'submitted_at', 'Recorded in TALA'),
                    'Your submitted version is preserved for Registrar review.',
                ],
                'View your application',
                '/applicant/application',
            ],
            OperationalEvent::TypeAdmissionCorrectionRequested => [
                'Action needed for your TALA application',
                'Correct named application items',
                [
                    "Application reference: {$reference}",
                    'Items: '.implode(', ', $this->payloadStringList($payload, 'affected_items', ['See the named items in TALA'])),
                    $this->payloadString($payload, 'instruction', 'Review the correction request in TALA.'),
                    'Due: '.$this->payloadString($payload, 'due_at', 'See TALA'),
                ],
                'Review required corrections',
                '/applicant/application',
            ],
            OperationalEvent::TypeAdmissionApplicationAdmitted => [
                'Your TALA admission result is available',
                'Admission result: Admitted',
                [
                    "Application reference: {$reference}",
                    $this->payloadString($payload, 'applicant_explanation', 'Your safe admission result is available in TALA.'),
                    ...$this->payloadStringList($payload, 'credential_instructions', ['Review your official credential instructions in TALA.']),
                ],
                'View admission result',
                '/applicant/application',
            ],
            OperationalEvent::TypeAdmissionApplicationNotAdmitted => [
                'Your TALA admission result is available',
                'Admission result: Not admitted',
                [
                    "Application reference: {$reference}",
                    $this->payloadString($payload, 'applicant_explanation', 'Your safe admission result is available in TALA.'),
                    'Support: '.$this->payloadString($payload, 'support_contact', 'See the official support path in TALA.'),
                ],
                'View admission history',
                '/applicant/application',
            ],
            OperationalEvent::TypeAdmissionReadyForEnrollment => [
                'You are ready to start enrollment in TALA',
                'Ready for enrollment',
                [
                    "Application reference: {$reference}",
                    'Your admission requirements due before registration are satisfied.',
                    'This is not yet proof of official enrollment.',
                ],
                'Start enrollment',
                '/applicant',
            ],
            OperationalEvent::TypeAdmissionApplicationWithdrawn => [
                'Your TALA application withdrawal is recorded',
                'Application withdrawn',
                [
                    "Application reference: {$reference}",
                    'The application is no longer active for enrollment readiness.',
                    'Support: '.$this->payloadString($payload, 'support_contact', 'See the official support path in TALA.'),
                ],
                'View application history',
                '/applicant/application',
            ],
            default => throw ValidationException::withMessages(['event_type' => 'Unsupported admissions email type.']),
        };

        return new AdmissionsTransactionalMail(
            operationalEventId: $event->id,
            operationalEventType: $event->event_type,
            subjectLine: $subject,
            heading: $heading,
            safeLines: $lines,
            actionLabel: $actionLabel,
            actionUrl: url($path),
        );
    }

    private function queueRecorded(OperationalEvent $event): OperationalEvent
    {
        return DB::transaction(function () use ($event): OperationalEvent {
            $locked = OperationalEvent::query()->lockForUpdate()->findOrFail($event->id);

            if ($locked->status !== OperationalEvent::StatusPending) {
                return $locked;
            }

            $payload = is_array($locked->payload) ? $locked->payload : [];

            if (isset($payload['queued_at'])) {
                return $locked;
            }

            $payload['queued_at'] = now(config('app.timezone'))->toIso8601String();
            $locked->forceFill(['payload' => $payload])->save();
            $recipient = is_array($locked->recipient_snapshot) ? $locked->recipient_snapshot : [];
            $email = $recipient['email'] ?? null;

            if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return $this->markFailed($locked, 'The admissions notification recipient is unavailable.');
            }

            try {
                Mail::to($email)->queue($this->mailFor($locked));
            } catch (Throwable $exception) {
                return $this->markFailed($locked, 'The admissions mail could not be queued: '.class_basename($exception));
            }

            return $locked->refresh();
        }, attempts: 3);
    }

    private function authorizeActor(OperationalEvent $event, User $actor): void
    {
        $this->assertSupportedEvent($event);
        $application = AdmissionApplication::query()->findOrFail($event->related_record_id);

        if ($event->user_id !== $application->user_id) {
            throw ValidationException::withMessages([
                'event' => 'The admissions notification recipient no longer matches the bound Application.',
            ]);
        }
        $authorized = ($application->user_id === $actor->id
                && $actor->hasRole('applicant')
                && $actor->canAuthenticate())
            || ($actor->hasRole(User::StaffRoleRegistrar)
                && $actor->canAuthenticate()
                && $actor->can('approve-documents'));

        if (! $authorized) {
            throw new AuthorizationException('You are not authorized to retry this admissions notification.');
        }
    }

    private function assertSupportedEvent(OperationalEvent $event): void
    {
        if ($event->event_domain !== OperationalEvent::DomainNotifications
            || $event->integration !== OperationalEvent::IntegrationMail
            || $event->channel !== OperationalEvent::ChannelEmail
            || $event->related_record_type !== AdmissionApplication::class
            || blank($event->related_record_id)
            || ! in_array($event->event_type, $this->admissionsEventTypes(), true)) {
            throw ValidationException::withMessages([
                'event' => 'Only a supported Application-bound admissions notification can be retried.',
            ]);
        }
    }

    /** @return list<string> */
    private function admissionsEventTypes(): array
    {
        return [
            OperationalEvent::TypeAdmissionApplicationSubmitted,
            OperationalEvent::TypeAdmissionApplicationResubmitted,
            OperationalEvent::TypeAdmissionCorrectionRequested,
            OperationalEvent::TypeAdmissionApplicationAdmitted,
            OperationalEvent::TypeAdmissionApplicationNotAdmitted,
            OperationalEvent::TypeAdmissionReadyForEnrollment,
            OperationalEvent::TypeAdmissionApplicationWithdrawn,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function payloadString(array $payload, string $key, string $fallback): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && filled($value) ? $value : $fallback;
    }

    /** @param array<string, mixed> $payload @param list<string> $fallback @return list<string> */
    private function payloadStringList(array $payload, string $key, array $fallback): array
    {
        $values = $payload[$key] ?? null;

        if (! is_array($values)) {
            return $fallback;
        }

        $safe = array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && filled($value)));

        return $safe === [] ? $fallback : $safe;
    }
}
