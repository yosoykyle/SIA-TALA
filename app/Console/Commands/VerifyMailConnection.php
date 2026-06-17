<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class VerifyMailConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:verify-mail-connection
        {recipient? : Optional email address or list of addresses (comma-separated) to send the test email to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify that the configured SMTP settings can successfully send emails.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $recipientInput = $this->argument('recipient');

        // If no recipient is passed as a command-line argument, ask interactively
        if (empty($recipientInput)) {
            $defaultRecipient = config('mail.from.address') ?: 'hello@example.com';
            $recipientInput = $this->ask('What email address(es) should we send the test email to? (separate multiple with commas or spaces)', $defaultRecipient);
        }

        // Parse multiple recipients by splitting on commas, spaces, or semicolons
        $recipients = preg_split('/[\s,;]+/', $recipientInput, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($recipients)) {
            $this->error('Recipient email(s) are required to run this verification.');

            return self::FAILURE;
        }

        // Validate each email format
        $validRecipients = [];
        foreach ($recipients as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validRecipients[] = $email;
            } else {
                $this->warn("Skipping invalid email format: {$email}");
            }
        }

        if (empty($validRecipients)) {
            $this->error('No valid recipient email addresses provided.');

            return self::FAILURE;
        }

        // Ask for subject with a default value
        $subject = $this->ask('Enter the email subject', 'Laravel Mail Connection Test');
        if (empty($subject)) {
            $subject = 'Laravel Mail Connection Test';
        }

        // Ask for body with a default value
        $body = $this->ask('Enter the email body', 'This is an automated mail connection test from your application.');
        if (empty($body)) {
            $body = 'This is an automated mail connection test from your application.';
        }

        $this->info("\nAttempting to send a test email...");
        $this->line('Mailer:   '.config('mail.default'));
        $this->line('Host:     '.config('mail.mailers.smtp.host'));
        $this->line('Port:     '.config('mail.mailers.smtp.port'));
        $this->line('From:     '.config('mail.from.address'));
        $this->line('To:       '.implode(', ', $validRecipients));
        $this->line('Subject:  '.$subject);
        $this->line('Body:     '.$body."\n");

        try {
            foreach ($validRecipients as $recipient) {
                Mail::raw($body, function ($message) use ($recipient, $subject) {
                    $message->to($recipient)
                        ->subject($subject);
                });
            }

            $this->info('SUCCESS: Test email(s) sent successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('ERROR: Failed to send email.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
