<?php

namespace App\Filament\Applicant\Pages\Auth;

use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\PasswordValidationRules;
use App\Filament\Pages\Auth\Concerns\HasAuthRateLimitCountdown;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Pages\Auth\Register;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class RegisterApplicant extends Register
{
    use HasAuthRateLimitCountdown;
    use PasswordValidationRules;

    /**
     * Fields in the order they appear on the form, so focus lands on the first invalid one.
     *
     * @var list<string>
     */
    private const FieldFocusOrder = ['email', 'password', 'password_confirmation', 'privacy_acknowledged'];

    public function mount(): void
    {
        $readiness = app(ApplicantEntryReadinessService::class);

        if (! $readiness->registrationIsAvailable()) {
            $reason = $readiness->admissionsAreOpen() ? 'unavailable' : 'closed';
            $this->redirect(route('home', ['registration' => $reason]));

            return;
        }

        parent::mount();

        $this->checkInitialRateLimitOnMount(
            rateLimitingKey: $this->getRateLimitKey('register'),
            maxAttempts: 2,
            context: 'register',
            totalSeconds: 60,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        return app(CreateNewUser::class)->create($data);
    }

    protected function sendEmailVerificationNotification(Model $user): void
    {
        if (! $user instanceof User || $user->hasVerifiedEmail()) {
            return;
        }

        if (! app(DispatchApplicantEmailVerification::class)->execute($user)) {
            session()->flash('applicant_verification_dispatch_failed', true);
        }
    }

    /**
     * @throws ValidationException
     */
    public function register(): ?RegistrationResponse
    {
        if (RateLimiter::tooManyAttempts($this->getRateLimitKey('register'), 2)) {
            return parent::register();
        }

        $this->clearAuthRateLimit();

        try {
            $this->form->getState();

            return parent::register();
        } catch (ValidationException $exception) {
            $firstInvalidField = $this->firstInvalidField($exception);

            if ($firstInvalidField !== null) {
                $this->dispatch('focus-applicant-'.$firstInvalidField);
            }

            throw $exception;
        }
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        $this->data = [
            'email' => null,
            'password' => null,
            'password_confirmation' => null,
            'privacy_acknowledged' => false,
        ];
        $this->form->fill($this->data);
        $this->resetErrorBag();
        $this->resetValidation();
        $this->startAuthRateLimitCountdown($exception->secondsUntilAvailable, 'register', 60);

        return parent::getRateLimitedNotification($exception);
    }

    private function firstInvalidField(ValidationException $exception): ?string
    {
        $invalidFields = array_map(
            fn (string $key): string => Str::after($key, 'data.'),
            array_keys($exception->errors()),
        );

        foreach (self::FieldFocusOrder as $field) {
            if (in_array($field, $invalidFields, true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Evaluates the live 15–64 character length guidance against the unified password policy.
     *
     * @return array{length: int, met: bool, too_long: bool, label: string}
     */
    private function passwordLengthCheck(string $password): array
    {
        $length = mb_strlen($password);
        $met = $length >= 15 && $length <= 64;
        $tooLong = $length > 64;

        $label = match (true) {
            $length === 0 => '0 of 15–64 characters entered',
            $tooLong => "{$length} of 15–64 characters entered (maximum 64)",
            $met => "{$length} of 15–64 characters entered (ready)",
            default => "{$length} of 15–64 characters entered (minimum 15)",
        };

        return [
            'length' => $length,
            'met' => $met,
            'too_long' => $tooLong,
            'label' => $label,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function focusOnInvalidFieldAttributes(string $field): array
    {
        return ['x-on:focus-applicant-'.$field.'.window' => '$nextTick(() => $el.focus())'];
    }

    public function form(Schema $schema): Schema
    {
        $privacyUrl = app(ApplicantEntryReadinessService::class)->officialReferences()['privacy'];

        return $schema->components([
            TextInput::make('email')
                ->label('Email address')
                ->email()
                ->required()
                ->maxLength(255)
                ->autocomplete('email')
                ->extraInputAttributes($this->focusOnInvalidFieldAttributes('email'))
                ->autofocus(),
            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->rules($this->passwordRules())
                ->showAllValidationMessages()
                ->autocomplete('new-password')
                ->extraInputAttributes([
                    ...$this->focusOnInvalidFieldAttributes('password'),
                    'data-tala-password-input' => 'password',
                ]),
            Placeholder::make('password_requirements')
                ->hiddenLabel()
                ->content(fn (Get $get): View => view('filament.applicant.components.password-requirements', [
                    'check' => $this->passwordLengthCheck((string) $get('password')),
                    'password' => (string) $get('password'),
                    'passwordConfirmation' => (string) $get('password_confirmation'),
                ])),
            TextInput::make('password_confirmation')
                ->label('Confirm password')
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->autocomplete('new-password')
                ->extraInputAttributes([
                    ...$this->focusOnInvalidFieldAttributes('password_confirmation'),
                    'data-tala-password-input' => 'password_confirmation',
                ]),
            Checkbox::make('privacy_acknowledged')
                ->label(new HtmlString(
                    'I acknowledge the <a class="underline" href="'.e($privacyUrl).'" target="_blank" rel="noopener noreferrer">Privacy Notice</a>.',
                ))
                ->accepted()
                ->required()
                ->extraInputAttributes($this->focusOnInvalidFieldAttributes('privacy_acknowledged')),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Create your applicant account';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Create your applicant account';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Use an active email address to start your admission application and track your progress.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE),
                $this->getRateLimitCountdownContentComponent(),
                $this->getFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_REGISTER_FORM_AFTER),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()
            ->extraAttributes(['novalidate' => true])
            ->visible(fn (): bool => ! $this->isAuthRateLimited());
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()
            ->label('Create account');
    }
}
