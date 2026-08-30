<?php

namespace App\Listeners;

use App\Models\OperationalEvent;
use Illuminate\Mail\Events\MessageSent;

class RecordOperationalMailSent
{
    public function handle(MessageSent $event): void
    {
        $operationalEventId = $event->data['operationalEventId'] ?? null;
        $operationalEventType = $event->data['operationalEventType'] ?? null;

        if (! is_numeric($operationalEventId)
            || ! is_string($operationalEventType)
            || ! in_array($operationalEventType, [
                OperationalEvent::TypeScheduleRevisionEmail,
                OperationalEvent::TypeScheduleReleasedEmail,
                OperationalEvent::TypeFacultyAvailabilityRequestedEmail,
                OperationalEvent::TypePaymentPostedEmail,
                OperationalEvent::TypeApplicantActionRequiredEmail,
                OperationalEvent::TypeApplicantApprovedEmail,
                OperationalEvent::TypeAdmissionApplicationSubmitted,
                OperationalEvent::TypeAdmissionApplicationResubmitted,
                OperationalEvent::TypeAdmissionCorrectionRequested,
                OperationalEvent::TypeAdmissionApplicationAdmitted,
                OperationalEvent::TypeAdmissionApplicationNotAdmitted,
                OperationalEvent::TypeAdmissionReadyForEnrollment,
                OperationalEvent::TypeAdmissionApplicationWithdrawn,
                ...OperationalEvent::registrationNotificationTypes(),
                ...OperationalEvent::academicNotificationTypes(),
            ], true)) {
            return;
        }

        $deliveryEvent = OperationalEvent::query()
            ->whereKey((int) $operationalEventId)
            ->where('event_type', $operationalEventType)
            ->where('status', OperationalEvent::StatusPending)
            ->first();

        if (! $deliveryEvent instanceof OperationalEvent) {
            return;
        }

        $timestamp = now();
        $payload = $deliveryEvent->getAttribute('payload');
        $payload = is_array($payload) ? $payload : [];
        $payload['delivery'] = [
            'transport_message_id' => $event->sent->getMessageId(),
            'accepted_at' => $timestamp->toIso8601String(),
        ];
        $attemptId = $event->data['deliveryAttemptId'] ?? null;
        $payload['delivery_attempts'] = collect($payload['delivery_attempts'] ?? [])->map(function (array $attempt) use ($attemptId, $timestamp, $event): array {
            if (($attempt['attempt_id'] ?? null) !== $attemptId) {
                return $attempt;
            }

            return [...$attempt, 'status' => OperationalEvent::StatusProcessed, 'accepted_at' => $timestamp->toIso8601String(), 'transport_message_id' => $event->sent->getMessageId()];
        })->all();

        $deliveryEvent->forceFill([
            'status' => OperationalEvent::StatusProcessed,
            'processed_at' => $timestamp,
            'sent_at' => $timestamp,
            'failed_at' => null,
            'diagnostics' => null,
            'payload' => $payload,
        ])->save();
    }
}
