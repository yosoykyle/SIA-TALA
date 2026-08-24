<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Authentication\UserSessionService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Password;
use LogicException;

class AccountSecurity extends EditProfile
{
    protected static ?string $title = 'Account Security';

    protected static ?string $slug = 'account-security';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
            Section::make('Authorized access and sessions')
                ->description('These contexts come from your approved account access. Choosing a workspace never grants a role.')
                ->schema([
                    Text::make(function (): string {
                        $user = $this->getUser();

                        if (! $user instanceof User) {
                            return 'No authorized workspace is available.';
                        }

                        $contexts = app(WorkspaceContextResolver::class)->availableContexts($user);

                        return 'Authorized contexts: '.collect($contexts)->pluck('label')->implode(', ');
                    }),
                    Text::make(function (): string {
                        $user = $this->getUser();

                        if (! $user instanceof User) {
                            return 'Session policy unavailable.';
                        }

                        $minutes = app(UserSessionService::class)->idleTimeoutMinutes($user);
                        $remember = app(UserSessionService::class)->rememberAllowed($user)
                            ? 'Remember device is optional.'
                            : 'Remember device is unavailable for Staff-capable accounts.';

                        return "Session guidance: {$minutes}-minute idle timeout. {$remember}";
                    }),
                    Text::make(function (): string {
                        $user = $this->getUser();

                        if (! $user instanceof User || ! $user->isStaffCapable()) {
                            return 'Identity source: this account profile.';
                        }

                        $identifier = $user->staffAccessProfile?->staff_identifier;

                        return 'Staff identity: '.$user->getFilamentName().(filled($identifier) ? " ({$identifier})" : '');
                    }),
                    Action::make('switchWorkspace')
                        ->label('Switch workspace')
                        ->url(route('workspace-chooser'))
                        ->visible(function (): bool {
                            $user = $this->getUser();

                            return $user instanceof User
                                && count(app(WorkspaceContextResolver::class)->availableContexts($user)) > 1;
                        }),
                ]),
        ]);
    }

    protected function getNameFormComponent(): TextInput
    {
        return TextInput::make('name')
            ->label('Account identity')
            ->disabled()
            ->dehydrated(false)
            ->formatStateUsing(fn (?string $state): string => filled($state) ? $state : 'Applicant account');
    }

    protected function getEmailFormComponent(): TextInput
    {
        $component = parent::getEmailFormComponent();

        if (! $component instanceof TextInput) {
            throw new LogicException('The Filament profile email component must be a text input.');
        }

        return $component
            ->label('Verified sign-in email')
            ->disabled(fn (): bool => $this->getUser() instanceof User && $this->getUser()->isStaffCapable())
            ->helperText(fn (): string => $this->getUser() instanceof User && $this->getUser()->isStaffCapable()
                ? 'Staff-capable account email changes are managed by a System Administrator.'
                : 'Your current email remains active until the successor address is verified.');
    }

    protected function getPasswordFormComponent(): TextInput
    {
        $component = parent::getPasswordFormComponent();

        if (! $component instanceof TextInput) {
            throw new LogicException('The Filament profile password component must be a text input.');
        }

        return $component
            ->rule(Password::min(15)->max(64)->uncompromised())
            ->helperText('Use 15–64 characters. Spaces and password-manager paste are allowed.');
    }
}
