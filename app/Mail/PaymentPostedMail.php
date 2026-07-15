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

class PaymentPostedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public string $operationalEventType = OperationalEvent::TypePaymentPostedEmail;

    public function __construct(
        public int $operationalEventId,
        public string $recipientName,
        public string $amount,
        public string $termLabel,
        public string $financeUrl,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment posted to your student ledger',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.payment-posted',
        );
    }

    public function failed(Throwable $exception): void
    {
        $deliveryEvent = OperationalEvent::query()
            ->whereKey($this->operationalEventId)
            ->where('event_type', $this->operationalEventType)
            ->where('status', OperationalEvent::StatusPending)
            ->first();

        if (! $deliveryEvent instanceof OperationalEvent) {
            return;
        }

        $timestamp = now();
        $deliveryEvent->forceFill([
            'status' => OperationalEvent::StatusFailed,
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
