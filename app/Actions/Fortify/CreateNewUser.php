<?php

namespace App\Actions\Fortify;

use App\Actions\Applicants\AdmissionWindowService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private AdmissionWindowService $admissionWindowService,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        if (! $this->admissionWindowService->hasOpenAdmissionsWindow()) {
            throw ValidationException::withMessages([
                'email' => 'Applications are currently closed. You may sign in to an existing applicant account.',
            ]);
        }

        Validator::make($input, [
            'name' => ['required_without:first_name', 'string', 'max:255'],
            'first_name' => ['required_with:last_name', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required_with:first_name', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:40'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        $namePayload = filled($input['first_name'] ?? null) && filled($input['last_name'] ?? null)
            ? User::staffNamePayload(
                $input['first_name'],
                $input['middle_name'] ?? null,
                $input['last_name'],
                $input['suffix'] ?? null,
            )
            : ['name' => $input['name']];

        return DB::transaction(function () use ($input, $namePayload): User {
            $user = User::create([
                ...$namePayload,
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            Role::findOrCreate('applicant', 'web');
            $user->assignRole('applicant');

            return $user;
        });
    }
}
