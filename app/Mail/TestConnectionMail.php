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
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');
        $app = (string) config('app.name', 'TALA');

        return new Envelope(
            subject: "{$institution} — Mail Connection Test (Powered by {$app})",
        );
    }

    public function content(): Content
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');
        $app = (string) config('app.name', 'TALA');

        return new Content(
            htmlString: "This is an automated mail self-test for {$institution} triggered from System Health by the signed-in administrator (Powered by {$app}).",
        );
    }
}
