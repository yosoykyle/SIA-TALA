<?php

namespace App\Filament\Applicant\Pages\Auth;

use App\Actions\Applicants\AdmissionWindowService;
use App\Models\User;
use Caresome\FilamentAuthDesigner\Pages\Auth\Register;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Spatie\Permission\Models\Role;

class RegisterApplicant extends Register
{
    public function mount(): void
    {
        if (! app(AdmissionWindowService::class)->hasOpenAdmissionsWindow()) {
            $this->redirect(route('home', ['admissions' => 'closed']));

            return;
        }

        parent::mount();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        if (! app(AdmissionWindowService::class)->hasOpenAdmissionsWindow()) {
            throw ValidationException::withMessages([
                'email' => 'Applications are currently closed. You may sign in to an existing applicant account.',
            ]);
        }

        $user = User::create($data);

        Role::findOrCreate('applicant', 'web');
        $user->assignRole('applicant');

        return $user;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Apply Online';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Create Applicant Account';
    }

    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()
            ->label('Apply Online');
    }
}
