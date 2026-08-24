<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
            htmlString: 'This is an automated TALA mail self-test triggered from System Health by the signed-in administrator.',
        );
    }
}
