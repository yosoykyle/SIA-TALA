<?php

namespace App\Actions\StudentProfiles;

use App\Actions\Authentication\DispatchPasswordRecovery;
use App\Models\OperationalEvent;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class StudentAccountActivation
{
    public const StatusPendingPasswordSetup = 'Pending password setup';

    public const StatusPasswordSet = 'Password set (awaiting sign-in)';

    public const StatusActivated = 'Activated & signed in';

    public const StatusNoAccount = 'No linked account';

    public function __construct(private readonly DispatchPasswordRecovery $passwordRecovery) {}

    public static function statusLabel(StudentProfile $profile): string
    {
        $user = $profile->user;

        if (! $user instanceof User) {
            return self::StatusNoAccount;
        }

        if ($user->password === null || trim((string) $user->password) === '') {
            return self::StatusPendingPasswordSetup;
        }

        if ($user->last_sign_in_at !== null) {
            return self::StatusActivated;
        }

        return self::StatusPasswordSet;
    }

    public static function canResendActivation(StudentProfile $profile): bool
    {
        $user = $profile->user;

        if (! $user instanceof User || ! $user->canAuthenticate()) {
            return false;
        }

        return $user->password === null
            || trim((string) $user->password) === ''
            || ($user->email_verified_at === null && $user->last_sign_in_at === null);
    }

    public static function canCorrectUnactivatedEmail(StudentProfile $profile): bool
    {
        return self::canResendActivation($profile);
    }

    /**
     * Dispatch a password-setup activation email for an imported/unactivated student account.
     */
    public function sendActivationEmail(StudentProfile $profile, User $actor, bool $skipAuthorization = false): bool
    {
        if (! $skipAuthorization && ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may dispatch student activation emails.');
        }

        $profile->loadMissing('user');
        $user = $profile->user;

        if (! $user instanceof User || blank($user->email)) {
            throw ValidationException::withMessages([
                'email' => 'This student profile does not have a linked sign-in email address.',
            ]);
        }

        if (! self::canResendActivation($profile)) {
            throw ValidationException::withMessages([
                'email' => 'This student account has already completed activation.',
            ]);
        }

        $panel = Filament::getPanel('student');

        try {
            $status = $this->passwordRecovery->sendViaBroker(
                credentials: ['email' => (string) $user->email],
                panel: $panel,
                expectedUser: $user,
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->recordOperationalEvent($user, $profile, OperationalEvent::StatusFailed, 'Activation email dispatch failed.');

            return false;
        }

        $sent = $status === Password::RESET_LINK_SENT;

        $this->recordOperationalEvent(
            user: $user,
            profile: $profile,
            status: $sent ? OperationalEvent::StatusProcessed : OperationalEvent::StatusFailed,
            summary: $sent
                ? 'Student activation email dispatched.'
                : 'Student activation email was not sent (status: '.$status.').',
        );

        if ($sent) {
            activity('student_onboarding')
                ->performedOn($profile)
                ->causedBy($actor)
                ->event('student_activation_email_sent')
                ->withProperties([
                    'student_number' => $profile->student_number,
                    'email' => $user->email,
                ])
                ->log('Student activation email sent');
        }

        return $sent;
    }

    /**
     * Correct the email address of an unactivated student account and immediately send a fresh activation link.
     */
    public function correctEmailAndResend(StudentProfile $profile, string $newEmail, User $actor): bool
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only an authorized Registrar may correct an unactivated student email.');
        }

        $profile->loadMissing('user');
        $user = $profile->user;

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'email' => 'This student profile does not have a linked user account.',
            ]);
        }

        if (! self::canCorrectUnactivatedEmail($profile)) {
            throw ValidationException::withMessages([
                'email' => 'This student has already activated their account. Use self-service Profile or IT Staff account email change instead.',
            ]);
        }

        $normalized = strtolower(trim($newEmail));

        Validator::make(
            ['email' => $normalized],
            [
                'email' => [
                    'required',
                    'string',
                    'email:rfc',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($user->id),
                ],
            ],
            [
                'email.unique' => 'Another TALA account already uses this email address.',
            ],
        )->validate();

        $previousEmail = (string) $user->email;

        DB::transaction(function () use ($profile, $user, $normalized, $previousEmail, $actor): void {
            $lockedProfile = StudentProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            $lockedUser->forceFill([
                'email' => $normalized,
                'email_verified_at' => null,
            ])->save();

            $lockedProfile->forceFill([
                'email' => $normalized,
            ])->save();

            DB::table('password_reset_tokens')
                ->whereIn('email', array_values(array_unique([$previousEmail, $normalized])))
                ->delete();

            activity('student_onboarding')
                ->performedOn($lockedProfile)
                ->causedBy($actor)
                ->event('student_activation_email_corrected')
                ->withProperties([
                    'student_number' => $lockedProfile->student_number,
                    'previous_email' => $previousEmail,
                    'new_email' => $normalized,
                ])
                ->log('Unactivated student email corrected before resending activation');
        }, attempts: 3);

        $profile->refresh()->load('user');

        return $this->sendActivationEmail($profile, $actor);
    }

    private function recordOperationalEvent(User $user, StudentProfile $profile, string $status, string $summary): void
    {
        $now = now();

        OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainNotifications,
            'integration' => OperationalEvent::IntegrationMail,
            'channel' => OperationalEvent::ChannelEmail,
            'direction' => OperationalEvent::DirectionOutbound,
            'event_type' => 'student_activation_email',
            'status' => $status,
            'user_id' => $user->id,
            'recipient_snapshot' => [
                'email' => (string) $user->email,
                'student_number' => (string) $profile->student_number,
            ],
            'related_record_type' => StudentProfile::class,
            'related_record_id' => $profile->id,
            'diagnostics' => ['summary' => $summary],
            'occurred_at' => $now,
            'processed_at' => $status === OperationalEvent::StatusProcessed ? $now : null,
            'sent_at' => $status === OperationalEvent::StatusProcessed ? $now : null,
            'failed_at' => $status === OperationalEvent::StatusFailed ? $now : null,
        ]);
    }
}
