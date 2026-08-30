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

class RegistrationJourneyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $operationalEventId,
        public string $operationalEventType,
        public string $recipientName,
        public string $subjectLine,
        public string $heading,
        public string $messageLine,
        public string $actionLabel,
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
            subject: $this->subjectLine,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.registration-journey',
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
        $event = OperationalEvent::query()
            ->whereKey($this->operationalEventId)
            ->where('event_type', $this->operationalEventType)
            ->where('status', OperationalEvent::StatusPending)
            ->first();

        if ($event instanceof OperationalEvent) {
            $event->update([
                'status' => OperationalEvent::StatusFailed,
                'processed_at' => now(),
                'failed_at' => now(),
                'diagnostics' => [
                    'reason' => 'Mail delivery failed.',
                    'exception_type' => class_basename($exception),
                ],
            ]);
        }
    }
}
