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
                OperationalEvent::TypePaymentPostedEmail,
                OperationalEvent::TypeApplicantActionRequiredEmail,
                OperationalEvent::TypeApplicantApprovedEmail,
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
