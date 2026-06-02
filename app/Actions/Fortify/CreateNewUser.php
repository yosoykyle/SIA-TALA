<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
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

        return User::create([
            ...$namePayload,
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);
    }
}
