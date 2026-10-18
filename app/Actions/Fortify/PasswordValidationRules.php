<?php

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::defaults(), 'confirmed'];
    }

    /**
     * Applicant registration rule: 8–64 characters with an uppercase letter, a lowercase letter, a number and a special character.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function applicantPasswordRules(): array
    {
        return ['required', 'string', Password::min(8)->max(64)->mixedCase()->numbers()->symbols()->uncompromised(), 'confirmed'];
    }
}
