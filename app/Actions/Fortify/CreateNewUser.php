<?php

namespace App\Actions\Fortify;

use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    private const DuplicateAccountMessage = 'An account could not be created with those details. Try signing in or use account recovery.';

    public function __construct(
        private ApplicantEntryReadinessService $applicantEntryReadinessService,
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
        if (! $this->applicantEntryReadinessService->registrationIsAvailable()) {
            throw ValidationException::withMessages([
                'email' => 'Applicant registration is not currently available. You may sign in to an existing applicant account.',
            ]);
        }

        $input['email'] = Str::lower(Str::squish((string) ($input['email'] ?? '')));

        $validated = Validator::make(
            $input,
            [
                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique(User::class),
                ],
                'password' => $this->passwordRules(),
                'privacy_acknowledged' => ['accepted'],
            ],
            ['email.unique' => self::DuplicateAccountMessage],
        )->validate();

        try {
            return DB::transaction(function () use ($validated): User {
                $user = User::create([
                    'name' => null,
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'status' => User::StatusApplicantPending,
                    'privacy_notice_reference' => $this->applicantEntryReadinessService->officialReferences()['privacy'],
                    'privacy_acknowledged_at' => now(),
                ]);

                Role::findOrCreate('applicant', 'web');
                $user->assignRole('applicant');

                return $user;
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueEmailViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => self::DuplicateAccountMessage,
            ]);
        }
    }

    private function isUniqueEmailViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && Str::contains(Str::lower($exception->getMessage()), ['email', 'users_email_unique']);
    }
}
