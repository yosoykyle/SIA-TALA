<?php

namespace App\Notifications;

use App\Models\PendingEmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendingEmailChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly PendingEmailChange $change,
        #[\SensitiveParameter] public readonly string $plainTextToken,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your new TALA sign-in email')
            ->line('A System Administrator requested this Staff sign-in email change.')
            ->action('Verify new email', route('staff-email-changes.verify', [
                'change' => $this->change,
                'token' => $this->plainTextToken,
            ]))
            ->line('The current sign-in email remains active until this successor address is verified.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
