<?php

namespace App\Filament\Applicant\Pages\Auth;

use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Pages\Auth\Register;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class RegisterApplicant extends Register
{
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
        try {
            return parent::register();
        } catch (ValidationException $exception) {
            $firstInvalidField = $this->firstInvalidField($exception);

            if ($firstInvalidField !== null) {
                $this->dispatch('focus-applicant-'.$firstInvalidField);
            }

            throw $exception;
        }
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
     * Mirrors applicantPasswordRules() so the live checklist agrees with the server-side validation.
     *
     * @return list<array{label: string, met: bool}>
     */
    private function passwordRequirementChecks(string $password): array
    {
        return [
            ['label' => 'At least 8 characters', 'met' => mb_strlen($password) >= 8],
            ['label' => 'Uppercase letter', 'met' => preg_match('/\p{Lu}/u', $password) === 1],
            ['label' => 'Lowercase letter', 'met' => preg_match('/\p{Ll}/u', $password) === 1],
            ['label' => 'Number', 'met' => preg_match('/\pN/u', $password) === 1],
            ['label' => 'Special character', 'met' => preg_match('/\p{Z}|\p{S}|\p{P}/u', $password) === 1],
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
                ->rules($this->applicantPasswordRules())
                ->live(debounce: 300)
                ->showAllValidationMessages()
                ->autocomplete('new-password')
                ->extraInputAttributes($this->focusOnInvalidFieldAttributes('password')),
            Placeholder::make('password_requirements')
                ->hiddenLabel()
                ->content(fn (Get $get): View => view('filament.applicant.components.password-requirements', [
                    'checks' => $this->passwordRequirementChecks((string) $get('password')),
                ])),
            TextInput::make('password_confirmation')
                ->label('Confirm password')
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->autocomplete('new-password')
                ->extraInputAttributes($this->focusOnInvalidFieldAttributes('password_confirmation')),
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

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()
            ->label('Create account');
    }
}
