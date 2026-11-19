<?php

namespace App\Filament\Applicant\Pages\Auth;

use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Pages\Auth\EmailVerification;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;

class ApplicantEmailVerification extends EmailVerification
{
    public function resendNotificationAction(): Action
    {
        return Action::make('resendNotification')
            ->button()
            ->color('primary')
            ->icon(Heroicon::OutlinedEnvelope)
            ->label('Resend verification link')
            ->extraAttributes(['class' => 'w-full justify-center'])
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
        return 'Verify your account';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Verify your account';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $email = trim((string) ($this->applicant()->email ?? ''));

        if ($email !== '') {
            return new HtmlString('We sent a verification link to <strong>'.e($email).'</strong>. Open the newest link to continue your application.');
        }

        return 'We sent a verification link to your email address. Open the newest link to continue your application.';
    }

    public function content(Schema $schema): Schema
    {
        $supportUrl = app(ApplicantEntryReadinessService::class)->officialReferences()['support'];
        $supportLink = filled($supportUrl)
            ? new HtmlString('<p class="tala-auth-recovery tala-verification-support"><span>Need help?</span> <a href="'.e((string) $supportUrl).'">Contact School Support &rarr;</a></p>')
            : new HtmlString('<p class="tala-auth-recovery tala-verification-support">Need help? Contact School Support</p>');

        return $schema->components([
            Placeholder::make('verification_countdown')
                ->hiddenLabel()
                ->content(function (): View {
                    $expiresAt = DispatchApplicantEmailVerification::expiresAt();
                    $remainingSeconds = $expiresAt === null ? 0 : max(0, $expiresAt->getTimestamp() - now()->getTimestamp());

                    return view('filament.applicant.components.verification-countdown', [
                        'expiresAtMilliseconds' => $expiresAt?->getTimestampMs(),
                        'initialClock' => sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60),
                        'resendActionHtml' => new HtmlString($this->resendNotificationAction->toHtml()),
                    ]);
                }),
            $supportLink,
        ]);
    }

    private function applicant(): User
    {
        /** @var User $user */
        $user = filament()->auth()->user();

        return $user;
    }
}
