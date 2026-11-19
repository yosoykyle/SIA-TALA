<?php

namespace App\Filament\Pages\Auth;

use Caresome\FilamentAuthDesigner\Pages\Auth\EmailVerification as BaseEmailVerification;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * @property-read Action $logoutAction
 */
class EmailVerificationPrompt extends BaseEmailVerification
{
    public function getTitle(): string|Htmlable
    {
        return 'Verify your email address';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Verify your email address';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Confirm your email address to continue into your Servitech workspace.';
    }

    public function resendNotificationAction(): Action
    {
        return parent::resendNotificationAction()
            ->button()
            ->color('primary')
            ->icon(Heroicon::OutlinedEnvelope)
            ->label('Resend verification email')
            ->size('md')
            ->extraAttributes(['class' => 'w-full justify-center']);
    }

    public function logoutAction(): Action
    {
        return Action::make('logout')
            ->button()
            ->color('gray')
            ->outlined()
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->label('Sign out')
            ->size('md')
            ->url(Filament::getLogoutUrl())
            ->postToUrl()
            ->extraAttributes(['class' => 'w-full justify-center']);
    }

    public function content(Schema $schema): Schema
    {
        $email = trim((string) (Filament::auth()->user()?->getEmailForVerification() ?? ''));
        $lead = $email !== ''
            ? 'We sent a verification link to <strong>'.e($email).'</strong>. Open the link in your inbox to verify your email address.'
            : 'We sent a verification link to your email address. Open the link in your inbox to verify your email address.';

        return $schema->components([
            new HtmlString(
                '<div class="tala-auth-verify-card">'
                .'<div class="tala-auth-verify-card__body">'
                .'<p class="tala-auth-verify-card__lead">'.$lead.'</p>'
                .'<p class="tala-auth-verify-card__note">Check your spam or junk folder if you do not see it within a few minutes.</p>'
                .'</div>'
                .'<div class="tala-auth-verify-card__actions tala-workspace-feature__action">'
                .$this->resendNotificationAction->toHtml()
                .$this->logoutAction->toHtml()
                .'</div>'
                .'</div>'
            ),
        ]);
    }
}
