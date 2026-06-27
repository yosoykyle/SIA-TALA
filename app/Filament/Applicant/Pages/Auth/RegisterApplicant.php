<?php

namespace App\Filament\Applicant\Pages\Auth;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\Pages\Register;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;
use Spatie\Permission\Models\Role;

class RegisterApplicant extends Register
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
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
