<?php

namespace App\Mail;

use App\Models\OperationalEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ScheduleRevisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /** @var list<array<string, mixed>> */
    public array $scheduleChanges;

    /**
     * @param  array<string, mixed>  $revisionPayload
     */
    public function __construct(
        public int $operationalEventId,
        public string $recipientName,
        array $revisionPayload,
    ) {
        $this->scheduleChanges = $revisionPayload['changes'] ?? [];
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your published class schedule was updated',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.schedule-revision',
        );
    }

    public function failed(Throwable $exception): void
    {
        $deliveryEvent = OperationalEvent::query()
            ->whereKey($this->operationalEventId)
            ->where('event_type', 'schedule_revision_email')
            ->where('status', 'PENDING')
            ->first();

        if (! $deliveryEvent instanceof OperationalEvent) {
            return;
        }

        $timestamp = now();
        $deliveryEvent->forceFill([
            'status' => 'FAILED',
            'processed_at' => $timestamp,
            'sent_at' => null,
            'failed_at' => $timestamp,
            'diagnostics' => [
                'reason' => 'Mail delivery failed.',
                'exception_type' => class_basename($exception),
            ],
        ])->save();
    }
}
