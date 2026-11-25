<?php

namespace App\Filament\Applicant\Pages\Auth;

use App\Actions\Applicants\AdmissionWindowService;
use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\PasswordValidationRules;
use App\Filament\Pages\Auth\Concerns\HasAuthRateLimitCountdown;
use App\Models\AdmissionCycle;
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
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

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
        parent::mount();

        if (! $this->isRegistrationOpen()) {
            return;
        }

        $this->checkInitialRateLimitOnMount(
            rateLimitingKey: $this->getRateLimitKey('register'),
            maxAttempts: 2,
            context: 'register',
            totalSeconds: 60,
        );
    }

    public function isRegistrationOpen(): bool
    {
        try {
            return app(ApplicantEntryReadinessService::class)->registrationIsAvailable();
        } catch (QueryException $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * @return array{state: string, cycle: array{label: string, term: ?string, opens_at: ?string, closes_at: ?string}|null}
     */
    private function closedRegistrationContext(): array
    {
        try {
            $nextCycle = app(AdmissionWindowService::class)->nextPublishedCycle();

            if ($nextCycle instanceof AdmissionCycle) {
                $timezone = (string) config('app.display_timezone');

                return [
                    'state' => 'upcoming',
                    'cycle' => [
                        'label' => (string) $nextCycle->label,
                        'term' => $nextCycle->term?->label,
                        'opens_at' => $nextCycle->opens_at?->timezone($timezone)->format('F j, Y, g:i A'),
                        'closes_at' => $nextCycle->closes_at?->timezone($timezone)->format('F j, Y, g:i A'),
                    ],
                ];
            }

            $pastCycleExists = AdmissionCycle::query()
                ->where('state', AdmissionCycle::StatePublished)
                ->where('closes_at', '<=', now())
                ->exists();

            return [
                'state' => $pastCycleExists ? 'closed' : 'missing',
                'cycle' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'state' => 'unavailable',
                'cycle' => null,
            ];
        }
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
        if (! $this->isRegistrationOpen()) {
            $this->resetErrorBag();
            $this->resetValidation();

            Notification::make()
                ->title('Applications are currently closed')
                ->body('New applicant registration is unavailable right now. Existing accounts can still sign in.')
                ->warning()
                ->send();

            return null;
        }

        if (RateLimiter::tooManyAttempts($this->getRateLimitKey('register'), 2)) {
            return parent::register();
        }

        $this->clearAuthRateLimit();

        try {
            $this->form->getState();

            return parent::register();
        } catch (ValidationException $exception) {
            $exception = $this->withSchemaStatePathKeys($exception);

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

    /**
     * CreateNewUser (and any other Fortify-contract action) reports failures by plain field name
     * ('email'), since that is the Fortify convention and what its own tests assert. Filament's
     * schema binds each field at 'data.<field>', so a bare key never attaches to the matching
     * input and the error is dropped silently instead of showing under the field (#80-style fix,
     * analogous bug). Re-key here, once, rather than changing CreateNewUser's established contract.
     */
    private function withSchemaStatePathKeys(ValidationException $exception): ValidationException
    {
        $errors = collect($exception->errors())
            ->mapWithKeys(fn (array $messages, string $key): array => [
                Str::startsWith($key, 'data.') ? $key : 'data.'.$key => $messages,
            ])
            ->all();

        return ValidationException::withMessages($errors);
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
        return $this->isRegistrationOpen()
            ? 'Create your applicant account'
            : $this->closedHeading();
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->isRegistrationOpen()
            ? 'Create your applicant account'
            : $this->closedHeading();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->isRegistrationOpen()
            ? 'Use an active email address to start your admission application and track your progress.'
            : 'Online applicant registration is only available during an open admission cycle.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE),
                $this->getRegistrationClosedContentComponent(),
                $this->getRateLimitCountdownContentComponent()
                    ->visible(fn (): bool => $this->isRegistrationOpen() && $this->isAuthRateLimited()),
                $this->getFormContentComponent(),
                RenderHook::make(PanelsRenderHook::AUTH_REGISTER_FORM_AFTER),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()
            ->extraAttributes(['novalidate' => true])
            ->visible(fn (): bool => $this->isRegistrationOpen() && ! $this->isAuthRateLimited());
    }

    private function getRegistrationClosedContentComponent(): Component
    {
        return SchemaView::make('filament.applicant.components.registration-closed-card')
            ->key('applicant-registration-closed')
            ->viewData(fn (): array => $this->closedRegistrationContext())
            ->visible(fn (): bool => ! $this->isRegistrationOpen());
    }

    private function closedHeading(): string
    {
        return $this->closedRegistrationContext()['state'] === 'upcoming'
            ? 'Applications have not opened yet'
            : 'Applications are currently closed';
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()
            ->label('Create account');
    }
}
