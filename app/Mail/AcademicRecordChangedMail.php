<?php

namespace App\Mail;

use App\Models\OperationalEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AcademicRecordChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public int $operationalEventId,
        public string $operationalEventType,
        public string $deliveryAttemptId,
        public string $recipientName,
        public string $changeLabel,
        public string $actionUrl,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your TALA academic record was updated',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.academic-record-changed',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    public function failed(Throwable $exception): void
    {
        $event = OperationalEvent::query()->find($this->operationalEventId);

        if (! $event instanceof OperationalEvent || $event->event_type !== $this->operationalEventType) {
            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $payload['delivery_attempts'] = collect($payload['delivery_attempts'] ?? [])->map(function (array $attempt): array {
            if (($attempt['attempt_id'] ?? null) !== $this->deliveryAttemptId) {
                return $attempt;
            }

            return [...$attempt, 'status' => OperationalEvent::StatusFailed, 'failed_at' => now()->toIso8601String()];
        })->all();

        $event->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'failed_at' => now(),
            'diagnostics' => ['reason' => 'Queued mail delivery failed.', 'exception_type' => class_basename($exception)],
            'payload' => $payload,
        ])->save();
    }
}
