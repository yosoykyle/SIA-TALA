<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

class StudentAccountActivationNotification extends Notification
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter] public readonly string $token,
        public readonly string $url,
        public readonly ?string $studentNumber = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $institution = (string) config('institution.name', 'Servitech Institute Asia Inc.');
        $recipientName = $notifiable instanceof User && filled($notifiable->first_name)
            ? (string) $notifiable->first_name
            : ($notifiable instanceof User && filled($notifiable->name) ? (string) $notifiable->name : 'Student');

        $message = (new MailMessage)
            ->subject('Activate your Servitech Student Hub account')
            ->greeting("Welcome to {$institution}, {$recipientName}!")
            ->line('Your Servitech Student Hub account is ready. Set your password to open your study plan, class schedule, enrollment, and academic records.');

        if (filled($this->studentNumber)) {
            $message->line("Student number: {$this->studentNumber}");
        }

        return $message
            ->line('Choose a strong password between 15 and 64 characters long.')
            ->action('Activate your Student Hub account', $this->url)
            ->line('This activation link is valid for 7 days and can be used once.')
            ->line('If you did not expect this email or need help opening your account, contact the Registrar’s Office.')
            ->salutation("Regards,\n{$institution} via ".config('app.name'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
