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

class OfficialEnrollmentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $operationalEventId,
        public string $recipientName,
        public string $termLabel,
        public string $corUrl,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your official enrollment is confirmed',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.official-enrollment',
        );
    }

    public function failed(Throwable $exception): void
    {
        $event = OperationalEvent::query()
            ->whereKey($this->operationalEventId)
            ->where('event_type', OperationalEvent::TypeOfficialEnrollmentEmail)
            ->where('status', OperationalEvent::StatusPending)
            ->first();

        if ($event instanceof OperationalEvent) {
            $event->update([
                'status' => OperationalEvent::StatusFailed,
                'processed_at' => now(),
                'failed_at' => now(),
                'diagnostics' => ['reason' => 'Mail delivery failed.', 'exception_type' => class_basename($exception)],
            ]);
        }
    }
}
