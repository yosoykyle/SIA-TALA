<?php

namespace App\Listeners;

use App\Models\OperationalEvent;
use Illuminate\Mail\Events\MessageSent;

class RecordScheduleRevisionMailSent
{
    public function handle(MessageSent $event): void
    {
        $operationalEventId = $event->data['operationalEventId'] ?? null;

        if (! is_numeric($operationalEventId)) {
            return;
        }

        $deliveryEvent = OperationalEvent::query()
            ->whereKey((int) $operationalEventId)
            ->where('event_type', 'schedule_revision_email')
            ->where('status', 'PENDING')
            ->first();

        if (! $deliveryEvent instanceof OperationalEvent) {
            return;
        }

        $timestamp = now();
        $deliveryEvent->forceFill([
            'status' => 'PROCESSED',
            'processed_at' => $timestamp,
            'sent_at' => $timestamp,
            'failed_at' => null,
            'diagnostics' => null,
        ])->save();
    }
}
