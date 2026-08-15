<?php

namespace App\Filament\Applicant\Pages\Auth;

use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Pages\Auth\EmailVerification;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;

class ApplicantEmailVerification extends EmailVerification
{
    public function resendNotificationAction(): Action
    {
        return Action::make('resendNotification')
            ->link()
            ->label('Resend verification link')
            ->size('sm')
            ->action(function (): void {
                $applicant = $this->applicant();
                $rateLimitKey = 'applicant-email-verification-resend:'.$applicant->id;

                if (RateLimiter::tooManyAttempts($rateLimitKey, maxAttempts: 1)) {
                    Notification::make()
                        ->title('Please wait before trying again')
                        ->body('You can request another verification link in '.RateLimiter::availableIn($rateLimitKey).' seconds.')
                        ->danger()
                        ->send();

                    return;
                }

                RateLimiter::hit($rateLimitKey, decaySeconds: 60);

                if (app(DispatchApplicantEmailVerification::class)->execute($applicant)) {
                    Notification::make()
                        ->title('Verification request queued')
                        ->body('Use the newest verification link when it arrives. Earlier links no longer work.')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Verification request could not be queued')
                    ->body('Try again when the wait period ends, or use the official support option.')
                    ->danger()
                    ->persistent()
                    ->send();
            });
    }

    public function getTitle(): string|Htmlable
    {
        return 'Verify your email address';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Verify your Applicant account';
    }

    public function content(Schema $schema): Schema
    {
        $supportUrl = app(ApplicantEntryReadinessService::class)->officialReferences()['support'];
        $support = filled($supportUrl)
            ? new HtmlString('If verification remains unavailable, <a class="underline" href="'.e((string) $supportUrl).'">contact official support</a>.')
            : 'If verification remains unavailable, try again later.';

        return $schema->components([
            Text::make('A verification link was requested for '.$this->applicant()->email.'.'),
            Text::make('Only the newest verification link can be used. Links expire after 60 minutes.'),
            Text::make($support),
            Actions::make([
                $this->resendNotificationAction(),
                $this->logoutAction(),
            ]),
        ]);
    }

    private function applicant(): User
    {
        /** @var User $user */
        $user = filament()->auth()->user();

        return $user;
    }
}
