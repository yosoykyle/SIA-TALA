<?php

namespace App\Filament\Applicant\Pages\Auth;

use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Actions\Applicants\DispatchApplicantEmailVerification;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Pages\Auth\Register;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use SensitiveParameter;

class RegisterApplicant extends Register
{
    use PasswordValidationRules;

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
                ->autofocus(),
            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->rules($this->passwordRules())
                ->showAllValidationMessages()
                ->autocomplete('new-password'),
            TextInput::make('password_confirmation')
                ->label('Confirm password')
                ->password()
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->autocomplete('new-password'),
            Checkbox::make('privacy_acknowledged')
                ->label(new HtmlString(
                    'I acknowledge the <a class="underline" href="'.e($privacyUrl).'" target="_blank" rel="noopener noreferrer">Privacy Notice</a>.',
                ))
                ->accepted()
                ->required(),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Create Applicant Account';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Create Applicant Account';
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()
            ->label('Create account');
    }
}
