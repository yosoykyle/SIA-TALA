<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\SystemAdministration\StaffInvitationService;
use App\Filament\Pages\Auth\Concerns\HasAuthRateLimitCountdown;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\StaffInvitation;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Concerns\HasAuthDesignerLayout;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * @property-read Schema $form
 */
class StaffActivation extends SimplePage
{
    use HasAuthDesignerLayout;
    use HasAuthRateLimitCountdown;
    use WithRateLimiting;

    protected static string $layout = 'filament-auth-designer::components.layouts.auth';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    #[Locked]
    public int $invitationId = 0;

    #[Locked]
    public string $token = '';

    #[Locked]
    public bool $isUsable = false;

    #[Locked]
    public ?string $invitedName = null;

    #[Locked]
    public ?string $invitedEmail = null;

    #[Locked]
    public ?string $invitedStaffIdentifier = null;

    #[Locked]
    public string $invitedInitials = 'S';

    /**
     * @var list<string>
     */
    #[Locked]
    public array $invitedRoles = [];

    public function mount(StaffInvitation $invitation, StaffInvitationService $service): void
    {
        $this->invitationId = (int) $invitation->getKey();
        $token = (string) request()->query('token', '');
        $this->isUsable = $service->isUsable($invitation, $token, now());

        if (! $this->isUsable) {
            $this->markInvitationUnusable();

            return;
        }

        $this->token = $token;

        $invitedUser = $invitation->relationLoaded('user')
            ? $invitation->user
            : ($invitation->user_id ? $invitation->user : null);

        if ($invitedUser instanceof User) {
            $invitedUser->loadMissing('staffAccessProfile');
        }

        $this->invitedName = $invitedUser instanceof User && filled($invitedUser->name)
            ? (string) $invitedUser->name
            : null;
        $this->invitedEmail = filled($invitation->email)
            ? (string) $invitation->email
            : ($invitedUser instanceof User ? (string) $invitedUser->email : null);
        $this->invitedStaffIdentifier = $invitedUser instanceof User && filled($invitedUser->staffAccessProfile?->staff_identifier)
            ? (string) $invitedUser->staffAccessProfile->staff_identifier
            : null;
        $this->invitedInitials = $invitedUser instanceof User
            ? UsersTable::initials($invitedUser)
            : strtoupper(substr((string) ($this->invitedName ?? $this->invitedEmail ?? 'S'), 0, 2));
        $this->invitedRoles = collect(is_array($invitation->staff_roles) ? $invitation->staff_roles : [])
            ->filter(static fn (mixed $role): bool => is_string($role) && trim($role) !== '')
            ->map(static fn (string $role): string => UsersTable::roleLabel($role))
            ->values()
            ->all();

        $this->form->fill();

        $this->checkInitialRateLimitOnMount(
            rateLimitingKey: $this->getRateLimitKey('activate'),
            maxAttempts: 5,
            context: 'staff_activation',
            totalSeconds: 60,
        );
    }

    public function getTitle(): string|Htmlable
    {
        return 'Activate your account';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Activate your account';
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->isUsable) {
            return null;
        }

        return 'Set your password below. You will set up authenticator-app MFA on the next step.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getRateLimitCountdownContentComponent(),
                View::make('filament.components.staff-activation-invalid')
                    ->key('staff-activation-invalid')
                    ->visible(fn (): bool => ! $this->isUsable),
                View::make('filament.components.staff-activation-identity')
                    ->key('staff-activation-identity')
                    ->viewData(fn (): array => [
                        'invitedName' => $this->invitedName,
                        'invitedEmail' => $this->invitedEmail,
                        'invitedStaffIdentifier' => $this->invitedStaffIdentifier,
                        'invitedInitials' => $this->invitedInitials,
                        'invitedRoles' => $this->invitedRoles,
                    ])
                    ->visible(fn (): bool => $this->isUsable
                        && ! $this->isAuthRateLimited()
                        && ($this->invitedName !== null || $this->invitedEmail !== null || $this->invitedRoles !== [])),
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('activate')
            ->extraAttributes(['novalidate' => true])
            ->visible(fn (): bool => $this->isUsable && ! $this->isAuthRateLimited())
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->key('form-actions'),
            ]);
    }

    public function activate(): mixed
    {
        if (! $this->isUsable) {
            return null;
        }

        try {
            $this->rateLimit(5, decaySeconds: 60, method: 'activate');
        } catch (TooManyRequestsException $exception) {
            $this->data = [
                'password' => null,
                'passwordConfirmation' => null,
            ];
            $this->form->fill($this->data);
            $this->resetErrorBag();
            $this->resetValidation();
            $this->startAuthRateLimitCountdown($exception->secondsUntilAvailable, 'staff_activation', 60);
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $this->clearAuthRateLimit();

        $validated = $this->form->getState();
        $invitation = StaffInvitation::query()->find($this->invitationId);

        if (! $invitation instanceof StaffInvitation) {
            $this->markInvitationUnusable();

            return null;
        }

        $service = app(StaffInvitationService::class);

        try {
            $user = $service->activate($invitation, $this->token, (string) $validated['password'], now());
        } catch (ValidationException $exception) {
            if (array_key_exists('invitation', $exception->errors())) {
                $this->markInvitationUnusable();

                Notification::make()
                    ->title('Activation link unavailable')
                    ->body($exception->errors()['invitation'][0] ?? 'This activation link is expired or no longer valid. Request a new invitation.')
                    ->danger()
                    ->send();

                return null;
            }

            $mappedErrors = [];

            foreach ($exception->errors() as $field => $messages) {
                $mappedField = str_starts_with($field, 'data.') ? $field : 'data.'.$field;
                $mappedErrors[$mappedField] = $messages;
            }

            throw ValidationException::withMessages($mappedErrors);
        }

        $this->clearRateLimiter(method: 'activate');
        RateLimiter::clear($this->getRateLimitKey('activate'));

        Auth::login($user);
        session()->regenerate();

        $staffContext = collect($invitation->staff_roles)
            ->first(fn (string $role): bool => in_array($role, $user->roles->pluck('name')->all(), true));

        if (is_string($staffContext)) {
            app(WorkspaceContextResolver::class)->select($user, $staffContext);
        }

        return redirect()->route('filament.admin.auth.multi-factor-authentication.set-up-required');
    }

    protected function getPasswordFormComponent(): TextInput
    {
        return TextInput::make('password')
            ->label('Password')
            ->helperText('Use 15 to 64 characters. Spaces and password-manager paste are allowed.')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('new-password')
            ->autofocus()
            ->required()
            ->minLength(15)
            ->maxLength(64)
            ->rule(Password::min(15)->max(64)->uncompromised())
            ->same('passwordConfirmation')
            ->extraInputAttributes([
                'enterkeyhint' => 'next',
                'minlength' => 15,
                'maxlength' => 64,
            ])
            ->validationAttribute('password');
    }

    protected function getPasswordConfirmationFormComponent(): TextInput
    {
        return TextInput::make('passwordConfirmation')
            ->label('Confirm password')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('new-password')
            ->required()
            ->minLength(15)
            ->maxLength(64)
            ->dehydrated(false)
            ->extraInputAttributes([
                'enterkeyhint' => 'done',
                'minlength' => 15,
                'maxlength' => 64,
            ]);
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getActivateFormAction(),
        ];
    }

    public function getActivateFormAction(): Action
    {
        return Action::make('activate')
            ->label('Activate account')
            ->submit('activate');
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        return Notification::make()
            ->title('Too many activation attempts')
            ->body("Please try again in {$exception->secondsUntilAvailable} seconds.")
            ->danger();
    }

    protected function getRateLimitCountdownTitle(): ?string
    {
        return 'Too many activation attempts';
    }

    protected function getRateLimitCountdownSummary(): ?string
    {
        return 'To protect staff accounts, please wait until the timer finishes before submitting your password again.';
    }

    protected function getAuthDesignerPageKey(): string
    {
        return 'staff-activation';
    }

    private function markInvitationUnusable(): void
    {
        $this->isUsable = false;
        $this->token = '';
        $this->invitedName = null;
        $this->invitedEmail = null;
        $this->invitedStaffIdentifier = null;
        $this->invitedInitials = 'S';
        $this->invitedRoles = [];
    }
}
