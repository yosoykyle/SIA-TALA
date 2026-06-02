<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
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
                Rule::unique('users')->ignore($user->id),
            ],
        ])->validateWithBag('updateProfileInformation');

        $namePayload = filled($input['first_name'] ?? null) && filled($input['last_name'] ?? null)
            ? User::staffNamePayload(
                $input['first_name'],
                $input['middle_name'] ?? null,
                $input['last_name'],
                $input['suffix'] ?? null,
            )
            : ['name' => $input['name']];

        if ($input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input, $namePayload);
        } else {
            $user->forceFill([
                ...$namePayload,
                'email' => $input['email'],
            ])->save();
        }
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     * @param  array{name?:string,first_name?:string,middle_name?:?string,last_name?:string,suffix?:?string}  $namePayload
     */
    protected function updateVerifiedUser(User $user, array $input, array $namePayload): void
    {
        $user->forceFill([
            ...$namePayload,
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
