<?php

namespace App\Notifications;

use App\Models\StaffInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly StaffInvitation $invitation,
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
            ->subject('Activate your TALA Staff access')
            ->greeting('TALA Staff access invitation')
            ->line('A System Administrator approved Staff access for this email address.')
            ->line('The activation link expires in 60 minutes and can be used once.')
            ->action('Activate Staff access', route('staff-invitations.activate', [
                'invitation' => $this->invitation,
                'token' => $this->plainTextToken,
            ]))
            ->line('If you did not expect this invitation, contact the institution through its official support channel.');
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
