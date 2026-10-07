<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Authentication\UserSessionService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
        $user = $this->getUser();
        $showMfa = $user instanceof User && ($user->isStaffCapable()
            || collect(Filament::getMultiFactorAuthenticationProviders())
                ->contains(fn (MultiFactorAuthenticationProvider $provider): bool => $provider->isEnabled($user)));

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Email and password')
                    ->description('Manage your sign-in details. Confirm your current password when making a change.')
                    ->schema([$this->getFormContentComponent()])
                    ->columnSpan(['default' => 1, 'lg' => 2]),
                Grid::make(1)->schema([
                    ...($showMfa ? Arr::wrap($this->getMultiFactorAuthenticationContentComponent()) : []),
                    Section::make('Access and sessions')->compact()->schema([
                        TextEntry::make('availableWorkspaces')->label('Available workspaces')
                            ->state(fn (): string => collect(app(WorkspaceContextResolver::class)->availableContexts($this->getUser()))->pluck('label')->implode(', ')),
                        TextEntry::make('automaticSignOut')->label('Automatic sign-out')
                            ->state(fn (): string => app(UserSessionService::class)->idleTimeoutMinutes($this->getUser()).' minutes of inactivity'),
                        TextEntry::make('rememberDevice')->label('Remember device')
                            ->state(fn (): string => app(UserSessionService::class)->rememberAllowed($this->getUser()) ? 'Optional at sign-in' : 'Unavailable for Staff-capable accounts'),
                        TextEntry::make('staffIdentity')->label('Staff identity')
                            ->state(fn (): string => $this->getUser()->getFilamentName().(filled($this->getUser()->staffAccessProfile?->staff_identifier) ? ' ('.$this->getUser()->staffAccessProfile->staff_identifier.')' : ''))
                            ->visible(fn (): bool => $this->getUser() instanceof User && $this->getUser()->isStaffCapable()),
                        Action::make('switchWorkspace')->label('Switch workspace')->icon('heroicon-o-arrows-right-left')
                            ->url(route('workspace-chooser'))->color('gray')
                            ->visible(fn (): bool => count(app(WorkspaceContextResolver::class)->availableContexts($this->getUser())) > 1),
                    ]),
                ]),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        return $schema->inlineLabel(false)->columns(2);
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
            ->helperText('Use 15–64 characters. Spaces and password-manager paste are allowed.')
            ->columnSpanFull();
    }
}
