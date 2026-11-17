<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Authentication\DispatchPasswordRecovery;
use App\Actions\Authentication\ManageUserProfilePhoto;
use App\Actions\Authentication\UserSessionService;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Fortify\UpdateUserPassword;
use App\Filament\Student\Pages\Profile as StudentProfilePage;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

class AccountSecurity extends EditProfile
{
    protected static ?string $title = 'Profile';

    protected static ?string $slug = 'account-profile';

    protected string $view = 'filament.pages.profile';

    public function content(Schema $schema): Schema
    {
        $user = $this->getUser();
        $showMfa = $user instanceof User && ($user->isStaffCapable()
            || collect(Filament::getMultiFactorAuthenticationProviders())
                ->contains(fn (MultiFactorAuthenticationProvider $provider): bool => $provider->isEnabled($user)));

        $mfaComponents = $showMfa ? Arr::wrap($this->getMultiFactorAuthenticationContentComponent()) : [];

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 12])->schema([
                Section::make('Email and password')
                    ->description('Manage your sign-in details. Confirm your current password when making a change.')
                    ->extraAttributes(['class' => 'tala-profile-credentials-section'])
                    ->schema([$this->getFormContentComponent()])
                    ->columnSpan(['default' => 1, 'lg' => 7]),
                Grid::make(1)->schema([
                    ...$mfaComponents,
                    Section::make('Access and sessions')
                        ->compact()
                        ->extraAttributes(['class' => 'tala-profile-access-section'])
                        ->schema([
                            Grid::make(1)
                                ->extraAttributes(['class' => 'tala-profile-access-grid'])
                                ->schema([
                                    TextEntry::make('availableWorkspaces')
                                        ->label('Available workspaces')
                                        ->state(fn (): string => collect(app(WorkspaceContextResolver::class)->availableContexts($this->getUser()))->pluck('label')->implode(', '))
                                        ->visible(fn (): bool => $this->getUser() instanceof User && count(app(WorkspaceContextResolver::class)->availableContexts($this->getUser())) > 1)
                                        ->extraEntryWrapperAttributes(['class' => 'tala-profile-access-item']),
                                    TextEntry::make('automaticSignOut')
                                        ->label('Automatic sign-out')
                                        ->state(fn (): string => app(UserSessionService::class)->idleTimeoutMinutes($this->getUser()).' minutes of inactivity')
                                        ->extraEntryWrapperAttributes(['class' => 'tala-profile-access-item']),
                                    TextEntry::make('rememberDevice')
                                        ->label('Remember device')
                                        ->state('Optional at sign-in')
                                        ->visible(fn (): bool => $this->getUser() instanceof User && app(UserSessionService::class)->rememberAllowed($this->getUser()))
                                        ->extraEntryWrapperAttributes(['class' => 'tala-profile-access-item']),
                                ]),
                        ]),
                ])->columnSpan(['default' => 1, 'lg' => 5]),
            ]),
        ]);
    }

    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        if (! Filament::hasMultiFactorAuthentication()) {
            return null;
        }

        $user = Filament::auth()->user();

        return Section::make(__('filament-panels::auth/pages/edit-profile.multi_factor_authentication.label'))
            ->compact()
            ->extraAttributes(['class' => 'tala-profile-mfa-section'])
            ->schema(collect(Filament::getMultiFactorAuthenticationProviders())
                ->sort(fn (MultiFactorAuthenticationProvider $multiFactorAuthenticationProvider): int => $multiFactorAuthenticationProvider->isEnabled($user) ? 0 : 1)
                ->map(fn (MultiFactorAuthenticationProvider $multiFactorAuthenticationProvider): Component => Group::make($multiFactorAuthenticationProvider->getManagementSchemaComponents())
                    ->statePath($multiFactorAuthenticationProvider->getId())
                    ->extraAttributes(['class' => 'tala-profile-mfa-box']))
                ->all());
    }

    public function changePhotoAction(): Action
    {
        return Action::make('changePhoto')
            ->label(fn (): string => filled($this->getUser()->avatar_path) ? 'Change photo' : 'Upload photo')
            ->icon('heroicon-o-camera')
            ->color('gray')
            ->modalHeading('Profile photo')
            ->modalWidth(Width::Large)
            ->modalDescription('Choose a photo for your account profile. Your photo stays private.')
            ->modalSubmitActionLabel('Save photo')
            ->authorize(function (): bool {
                $user = $this->getUser();

                return $user instanceof User
                    && $user->canAuthenticate()
                    && (! $user->hasRole('student') || $user->hasAccessibleStudentProfile() || $user->isStaffCapable());
            })
            ->schema([
                ManageUserProfilePhoto::fileUploadField(),
            ])
            ->action(function (array $data): void {
                $actor = $this->getUser();
                abort_unless(
                    $actor instanceof User
                    && $actor->canAuthenticate()
                    && (! $actor->hasRole('student') || $actor->hasAccessibleStudentProfile() || $actor->isStaffCapable()),
                    403,
                );
                app(ManageUserProfilePhoto::class)->upload($actor, $data['photo'] ?? null);
                Notification::make()->title('Profile photo updated')->success()->send();
            });
    }

    public function removePhotoAction(): Action
    {
        return Action::make('removePhoto')
            ->label('Remove photo')
            ->icon('heroicon-o-trash')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Remove profile photo')
            ->modalDescription('Your account will return to showing your initials.')
            ->modalSubmitActionLabel('Remove photo')
            ->visible(fn (): bool => $this->getUser() instanceof User && filled($this->getUser()->avatar_path))
            ->authorize(fn (): bool => $this->getUser() instanceof User && $this->getUser()->canAuthenticate())
            ->action(function (): void {
                $actor = $this->getUser();
                abort_unless($actor instanceof User && $actor->canAuthenticate(), 403);
                app(ManageUserProfilePhoto::class)->remove($actor);
                Notification::make()->title('Profile photo removed')->success()->send();
            });
    }

    /**
     * @return array{
     *     name: string,
     *     email: string,
     *     initials: string,
     *     photoUrl: ?string,
     *     identifier: string,
     *     identifierLabel: ?string,
     *     roleBadge: string,
     *     studentProfileUrl: ?string,
     *     switchWorkspaceUrl: ?string,
     * }
     */
    public function profileIdentity(): array
    {
        $user = $this->getUser();
        abort_unless($user instanceof User, 403);

        $user->loadMissing(['staffAccessProfile', 'studentProfile', 'currentAdmissionApplication']);

        $name = $user->getFilamentName();
        $resolver = app(WorkspaceContextResolver::class);
        $contexts = $resolver->availableContexts($user);
        $selected = $resolver->selected($user) ?? array_key_first($contexts);

        $roleBadge = match (true) {
            is_string($selected) && isset(User::staffRoleOptions()[$selected]) => User::staffRoleOptions()[$selected],
            $selected === 'student' || $user->hasRole('student') => 'Student',
            $user->isStaffCapable() => 'Staff',
            default => 'Applicant',
        };

        $identifier = '';
        $identifierLabel = null;
        if ($user->isStaffCapable() && filled($user->staffAccessProfile?->staff_identifier)) {
            $identifier = (string) $user->staffAccessProfile->staff_identifier;
            $identifierLabel = 'Staff ID';
        } elseif ($user->hasRole('student') && filled($user->studentProfile?->student_number)) {
            $identifier = (string) $user->studentProfile->student_number;
            $identifierLabel = 'Student number';
        } elseif (filled($user->currentAdmissionApplication?->application_reference)) {
            $identifier = (string) $user->currentAdmissionApplication->application_reference;
            $identifierLabel = 'Application reference';
        }

        $initials = $this->resolveInitials($user, $name);

        $studentProfileUrl = Filament::getCurrentOrDefaultPanel()?->getId() === 'student'
            && $user->hasAccessibleStudentProfile()
            ? StudentProfilePage::getUrl(panel: 'student')
            : null;

        return [
            'name' => $name,
            'email' => (string) $user->email,
            'initials' => $initials,
            'photoUrl' => $user->getFilamentAvatarUrl(),
            'identifier' => $identifier,
            'identifierLabel' => $identifierLabel,
            'roleBadge' => $roleBadge,
            'studentProfileUrl' => $studentProfileUrl,
            'switchWorkspaceUrl' => count($contexts) > 1 ? route('workspace-chooser') : null,
        ];
    }

    private function resolveInitials(User $user, string $name): string
    {
        if ($user->hasCanonicalNameParts()) {
            return mb_strtoupper(mb_substr((string) $user->first_name, 0, 1).mb_substr((string) $user->last_name, 0, 1));
        }

        $words = preg_split('/\s+/', trim($name)) ?: [];
        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[count($words) - 1], 0, 1));
        }

        return mb_strtoupper(mb_substr(trim($name), 0, 2)) ?: 'U';
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction(),
            $this->getSaveFormAction()->label('Save changes'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancelChanges')
            ->label('Cancel')
            ->color('gray')
            ->action(fn () => $this->discardPendingCredentialEdits());
    }

    public function discardPendingCredentialEdits(): void
    {
        $this->fillForm();
        $this->clearCredentialSecrets();
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->resetErrorBag([
            'data.currentPassword',
            'data.password',
            'data.passwordConfirmation',
            'data.email',
        ]);

        if (! $this->hasPendingCredentialSubmission()) {
            $message = $this->getUser() instanceof User && $this->getUser()->isStaffCapable()
                ? 'Enter your current password and a new password to save changes.'
                : 'Change your sign-in email or enter a new password before saving.';

            throw ValidationException::withMessages([
                'data.password' => $message,
            ]);
        }

        parent::save();

        $this->clearCredentialSecrets();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $passwordChanged = filled($data['password'] ?? null);

        $updated = parent::handleRecordUpdate($record, $data);

        if ($passwordChanged && $updated instanceof User) {
            app(UpdateUserPassword::class)->completePasswordChange(
                $updated,
                request()->hasSession() ? request()->session()->getId() : null,
            );
        }

        return $updated;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->inlineLabel(false)
            ->columns(1)
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                Fieldset::make('Change password')
                    ->columns(1)
                    ->extraAttributes(['class' => 'tala-profile-password-box'])
                    ->schema([
                        $this->getCurrentPasswordFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
            ]);
    }

    protected function getNameFormComponent(): TextInput
    {
        return TextInput::make('name')
            ->label('Account identity')
            ->disabled()
            ->dehydrated(false)
            ->formatStateUsing(fn (?string $state): string => $this->getUser() instanceof User ? $this->getUser()->getFilamentName() : (filled($state) ? $state : 'Applicant account'));
    }

    protected function getEmailFormComponent(): TextInput
    {
        $component = parent::getEmailFormComponent();

        if (! $component instanceof TextInput) {
            throw new LogicException('The Filament profile email component must be a text input.');
        }

        return $component
            ->label('Verified sign-in email')
            ->live(condition: false)
            ->disabled(fn (): bool => $this->getUser() instanceof User && $this->getUser()->isStaffCapable())
            ->helperText(fn (): string => $this->getUser() instanceof User && $this->getUser()->isStaffCapable()
                ? 'Staff account email changes are managed by a System Administrator.'
                : 'Your current email remains active until the successor address is verified.');
    }

    protected function getCurrentPasswordFormComponent(): TextInput
    {
        $component = parent::getCurrentPasswordFormComponent();

        if (! $component instanceof TextInput) {
            throw new LogicException('The Filament profile current-password component must be a text input.');
        }

        return $component
            ->visible(true)
            ->required(fn (Get $get): bool => filled($get('password')) || $this->isEmailChanged($get))
            ->helperText(fn (): string => $this->getUser() instanceof User && $this->getUser()->isStaffCapable()
                ? 'Required to change your password.'
                : 'Required to change your password or sign-in email.')
            ->hintAction(DispatchPasswordRecovery::authenticatedAction())
            ->columnSpanFull();
    }

    protected function getPasswordFormComponent(): TextInput
    {
        $component = parent::getPasswordFormComponent();

        if (! $component instanceof TextInput) {
            throw new LogicException('The Filament profile password component must be a text input.');
        }

        return $component
            ->live(condition: false)
            ->required(fn (Get $get): bool => filled($get('passwordConfirmation')) || (filled($get('currentPassword')) && ! $this->isEmailChanged($get)))
            ->rule(function () {
                return function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
                    $user = $this->getUser();

                    if (filled($value) && $user instanceof User && Hash::check((string) $value, (string) $user->password)) {
                        $fail('Choose a new password that differs from your current password.');
                    }
                };
            })
            ->helperText('Use 15–64 characters. Spaces and password-manager paste are allowed.')
            ->columnSpanFull();
    }

    protected function getPasswordConfirmationFormComponent(): TextInput
    {
        $component = parent::getPasswordConfirmationFormComponent();

        if (! $component instanceof TextInput) {
            throw new LogicException('The Filament profile password confirmation component must be a text input.');
        }

        return $component
            ->visible(true)
            ->required(fn (Get $get): bool => filled($get('password')))
            ->columnSpanFull();
    }

    private function isEmailChanged(Get $get): bool
    {
        return $this->emailChangedFromCurrent($get('email'));
    }

    private function hasPendingCredentialSubmission(): bool
    {
        return $this->emailChangedFromCurrent($this->data['email'] ?? null)
            || filled($this->data['currentPassword'] ?? null)
            || filled($this->data['password'] ?? null)
            || filled($this->data['passwordConfirmation'] ?? null);
    }

    private function emailChangedFromCurrent(mixed $candidateEmail): bool
    {
        $user = $this->getUser();

        if (! $user instanceof User || $user->isStaffCapable()) {
            return false;
        }

        return trim((string) ($candidateEmail ?? '')) !== (string) $user->getAttributeValue('email');
    }

    private function clearCredentialSecrets(): void
    {
        $this->data['currentPassword'] = null;
        $this->data['password'] = null;
        $this->data['passwordConfirmation'] = null;
    }
}
