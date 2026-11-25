<?php

namespace App\Filament\Applicant\Pages\Auth;

use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Filament\Pages\Auth\Concerns\HasAuthRateLimitCountdown;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Pages\Auth\EmailVerification;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;

/**
 * @property-read Action $backToSignInAction
 */
class ApplicantEmailVerification extends EmailVerification
{
    use HasAuthRateLimitCountdown;

    public function resendNotificationAction(): Action
    {
        return Action::make('resendNotification')
            ->button()
            ->color('primary')
            ->icon(Heroicon::OutlinedEnvelope)
            ->label('Resend verification link')
            ->extraAttributes(['class' => 'w-full justify-center'])
            ->disabled(fn (): bool => $this->isAuthRateLimited())
            ->action(function (): void {
                $applicant = $this->applicant();
                $rateLimitKey = 'applicant-email-verification-resend:'.$applicant->id;

                if (RateLimiter::tooManyAttempts($rateLimitKey, maxAttempts: 1)) {
                    $seconds = RateLimiter::availableIn($rateLimitKey);
                    $this->startAuthRateLimitCountdown($seconds, 'email_verification', 60);

                    Notification::make()
                        ->title('Please wait before trying again')
                        ->body('You can request another verification link in '.$seconds.' seconds.')
                        ->danger()
                        ->send();

                    return;
                }

                $this->clearAuthRateLimit();
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

    public function backToSignInAction(): Action
    {
        return Action::make('backToSignIn')
            ->link()
            ->color('primary')
            ->icon(Heroicon::OutlinedArrowLeft)
            ->iconSize(IconSize::Small)
            ->label('Back to sign in')
            ->extraAttributes(['class' => 'min-h-[2.75rem] whitespace-nowrap'])
            ->action(function (): void {
                filament()->auth()->logout();
                session()->invalidate();
                session()->regenerateToken();

                $this->redirect(route('filament.applicant.auth.login'), navigate: false);
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
        return $schema->components([
            $this->getRateLimitCountdownContentComponent(),
            Placeholder::make('verification_countdown')
                ->hiddenLabel()
                ->visible(fn (): bool => ! $this->isAuthRateLimited())
                ->content(function (): View {
                    $expiresAt = DispatchApplicantEmailVerification::expiresAt();
                    $remainingSeconds = $expiresAt === null
                        ? null
                        : max(0, $expiresAt->getTimestamp() - now()->getTimestamp());

                    return view('filament.applicant.components.verification-countdown', [
                        'remainingSeconds' => $remainingSeconds,
                        'sentAtKey' => session(DispatchApplicantEmailVerification::SentAtSessionKey),
                        'initialClock' => $remainingSeconds === null
                            ? null
                            : sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60),
                        'resendActionHtml' => new HtmlString($this->resendNotificationAction->toHtml()),
                    ]);
                }),
            SchemaView::make('filament.applicant.components.verification-recovery-links')
                ->key('verification-recovery-links')
                ->viewData(fn (): array => [
                    'backToSignInActionHtml' => new HtmlString($this->backToSignInAction->toHtml()),
                ]),
        ]);
    }

    protected function getRateLimitCountdownTitle(): ?string
    {
        return 'Too many verification emails sent';
    }

    protected function getRateLimitCountdownSummary(): ?string
    {
        return 'Please wait until the timer finishes before requesting another verification email. You can still open the newest link already sent to your inbox.';
    }

    private function applicant(): User
    {
        /** @var User $user */
        $user = filament()->auth()->user();

        return $user;
    }
}
