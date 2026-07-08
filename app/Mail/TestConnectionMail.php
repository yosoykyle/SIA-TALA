<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TAL-92D: the "Send test email" action on `App\Filament\Pages\IntegrationStatus`
 * always targets only the acting admin's own address. A proper Mailable (rather
 * than `Mail::raw()`) is used so `Mail::fake()`/`Mail::assertSent()` can verify
 * the recipient in tests, since `MailFake::raw()` is a documented no-op.
 */
class TestConnectionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'TALA Mail Connection Test',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: 'This is an automated TALA mail-connection test triggered from Integration Status.',
        );
    }
}
