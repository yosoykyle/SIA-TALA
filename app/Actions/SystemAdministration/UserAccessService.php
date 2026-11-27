<?php

namespace App\Actions\SystemAdministration;

use App\Actions\Authentication\UserSessionService;
use App\Models\OperationalEvent;
use App\Models\PendingEmailChange;
use App\Models\User;
use App\Notifications\AccountAccessChangedNotification;
use App\Notifications\EmailChangeAlertNotification;
use App\Notifications\PendingEmailChangeNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserAccessService
{
    public function __construct(private readonly UserSessionService $sessions) {}

    public function disable(User $actor, User $target, string $reason, ?string $authority = null, ?string $evidenceReference = null): User
    {
        $this->authorizeAdministrator($actor);

        if ($actor->is($target)) {
            throw new AuthorizationException('You cannot disable your own account.');
        }

        return DB::transaction(function () use ($actor, $target, $reason, $authority, $evidenceReference): User {
            $this->lockAdministrators();
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $this->validateReason($reason);

            if ($locked->hasRole(User::StaffRoleSystemSuperAdmin) && $this->activeAdministratorCount() <= 1) {
                throw ValidationException::withMessages([
                    'status' => 'The final active System Administrator cannot be disabled.',
                ]);
            }

            $normalizedAuthority = filled($authority) ? trim((string) $authority) : '';
            $normalizedEvidence = filled($evidenceReference) ? trim((string) $evidenceReference) : null;

            $locked->forceFill([
                'status' => User::StatusDisabled,
                'disabled_at' => now(),
                'disabled_by' => $actor->id,
                'disabled_reason' => trim($reason),
                'disabled_authority' => $normalizedAuthority !== '' ? $normalizedAuthority : null,
                'disabled_evidence_reference' => $normalizedEvidence,
            ])->save();
            $this->sessions->revokeAll($locked);

            $this->audit($actor, $locked, 'staff_account_disabled', $reason, $normalizedAuthority, [
                'contexts_preserved' => $locked->roles()->pluck('name')->all(),
                'evidence_reference' => $normalizedEvidence,
            ]);
            $this->notifyAccessChangeAfterCommit($locked, 'All TALA workspaces for this account were disabled. Existing roles and records were preserved.');

            return $locked->refresh();
        });
    }

    public function reactivate(User $actor, User $target, string $reason, ?string $authority = null, ?string $evidenceReference = null): User
    {
        $this->authorizeAdministrator($actor);

        return DB::transaction(function () use ($actor, $target, $reason, $authority, $evidenceReference): User {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $this->validateReason($reason);

            if ($locked->status !== User::StatusDisabled) {
                throw ValidationException::withMessages([
                    'status' => 'Only a disabled account can be reactivated.',
                ]);
            }

            $normalizedAuthority = filled($authority) ? trim((string) $authority) : '';
            $normalizedEvidence = filled($evidenceReference) ? trim((string) $evidenceReference) : null;

            $locked->forceFill([
                'status' => User::StatusActive,
                'disabled_at' => null,
                'disabled_by' => null,
                'disabled_reason' => null,
                'disabled_authority' => null,
                'disabled_evidence_reference' => null,
            ])->save();

            $this->audit($actor, $locked, 'staff_account_reactivated', $reason, $normalizedAuthority, [
                'contexts_restored' => $locked->roles()->pluck('name')->all(),
                'evidence_reference' => $normalizedEvidence,
            ]);
            $this->notifyAccessChangeAfterCommit($locked, 'Your TALA account was reactivated with its previously authorized contexts.');

            return $locked->refresh();
        });
    }

    /** @param list<string> $roles */
    public function changeStaffRoles(User $actor, User $target, array $roles, string $reason, ?string $authority = null, ?string $evidenceReference = null): User
    {
        $this->authorizeAdministrator($actor);
        $roles = array_values(array_unique($roles));

        if ($roles === [] || array_diff($roles, User::staffRoleNames()) !== []) {
            throw ValidationException::withMessages([
                'roles' => 'Select one or more approved Staff roles.',
            ]);
        }

        return DB::transaction(function () use ($actor, $target, $roles, $reason, $authority, $evidenceReference): User {
            $this->lockAdministrators();
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $this->validateReason($reason);

            if (! $locked->isStaffCapable()) {
                throw ValidationException::withMessages([
                    'roles' => 'Use Invite Staff before granting a learner account its first Staff role.',
                ]);
            }

            $before = $locked->roles()->whereIn('name', User::staffRoleNames())->pluck('name')->all();

            if ($locked->hasRole(User::StaffRoleSystemSuperAdmin)
                && ! in_array(User::StaffRoleSystemSuperAdmin, $roles, true)
                && $this->activeAdministratorCount() <= 1) {
                throw ValidationException::withMessages([
                    'roles' => 'The final active System Administrator role cannot be removed.',
                ]);
            }

            if ($actor->is($locked) && $roles !== $before) {
                throw new AuthorizationException('Administrators cannot change their own Staff access.');
            }

            $learnerRoles = $locked->roles()->whereIn('name', ['applicant', 'student'])->pluck('name')->all();
            $locked->syncRoles([...$learnerRoles, ...$roles]);

            $normalizedAuthority = filled($authority) ? trim((string) $authority) : '';
            $normalizedEvidence = filled($evidenceReference) ? trim((string) $evidenceReference) : null;

            $this->audit($actor, $locked, 'staff_access_changed', $reason, $normalizedAuthority, [
                'before_contexts' => $before,
                'after_contexts' => $roles,
                'evidence_reference' => $normalizedEvidence,
            ]);
            $this->notifyAccessChangeAfterCommit($locked, 'Your authorized TALA Staff contexts changed. Review your Profile after signing in.');

            return $locked->refresh();
        });
    }

    public function updateStaffIdentifier(
        User $actor,
        User $target,
        ?string $staffIdentifier,
        string $reason,
        ?string $authority = null,
        ?string $evidenceReference = null,
    ): User {
        $this->authorizeAdministrator($actor);
        $this->validateReason($reason);

        $normalized = filled($staffIdentifier) ? Str::squish((string) $staffIdentifier) : null;

        validator(['staff_identifier' => $normalized], [
            'staff_identifier' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('staff_access_profiles', 'staff_identifier')->ignore($target->id, 'user_id'),
            ],
        ])->validate();

        return DB::transaction(function () use ($actor, $target, $normalized, $reason, $authority, $evidenceReference): User {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if (! $locked->isStaffCapable()) {
                throw ValidationException::withMessages([
                    'staff_identifier' => 'This action is limited to Staff-capable accounts.',
                ]);
            }

            $existing = $locked->staffAccessProfile()->first();
            $before = $existing?->staff_identifier;

            if ($before === $normalized) {
                return $locked->refresh();
            }

            $nameParts = preg_split('/\s+/', trim((string) $locked->name)) ?: [];
            $fallbackFirst = $nameParts[0] ?? 'Staff';
            $fallbackLast = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : $fallbackFirst;

            $locked->staffAccessProfile()->updateOrCreate(
                ['user_id' => $locked->id],
                [
                    'staff_identifier' => $normalized,
                    'first_name' => $existing?->first_name ?: ($locked->first_name ?: $fallbackFirst),
                    'middle_name' => $existing?->middle_name ?? $locked->middle_name,
                    'last_name' => $existing?->last_name ?: ($locked->last_name ?: $fallbackLast),
                    'suffix' => $existing?->suffix,
                ],
            );

            $normalizedAuthority = filled($authority) ? trim((string) $authority) : '';
            $normalizedEvidence = filled($evidenceReference) ? trim((string) $evidenceReference) : null;

            $this->audit($actor, $locked, 'staff_identifier_updated', $reason, $normalizedAuthority, [
                'before_staff_identifier' => $before,
                'after_staff_identifier' => $normalized,
                'evidence_reference' => $normalizedEvidence,
            ]);

            return $locked->refresh();
        });
    }

    public function requestStaffEmailChange(
        User $actor,
        User $target,
        string $newEmail,
        #[\SensitiveParameter] string $actorPassword,
        string $reason,
        ?string $authority = null,
    ): PendingEmailChange {
        return $this->requestEmailChange($actor, $target, $newEmail, $actorPassword, $reason, $authority);
    }

    public function requestEmailChange(
        User $actor,
        User $target,
        string $newEmail,
        #[\SensitiveParameter] string $actorPassword,
        string $reason,
        ?string $authority = null,
    ): PendingEmailChange {
        $this->authorizeAdministrator($actor);

        if (! Hash::check($actorPassword, (string) $actor->password)) {
            throw ValidationException::withMessages(['current_password' => 'The administrator password is incorrect.']);
        }

        $newEmail = Str::lower(trim($newEmail));
        validator(['email' => $newEmail], [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ])->validate();
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'Enter a specific reason of at least 10 characters.',
            ]);
        }

        return DB::transaction(function () use ($actor, $target, $newEmail, $reason, $authority): PendingEmailChange {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($locked->status === User::StatusInvitationPending) {
                throw ValidationException::withMessages([
                    'target' => 'Use Correct email & resend for a pending staff invitation.',
                ]);
            }

            if (User::query()->where('email', $newEmail)->whereKeyNot($locked->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'That email address cannot be used.']);
            }

            $locked->pendingEmailChanges()->whereNull('verified_at')->whereNull('superseded_at')->update(['superseded_at' => now()]);
            $token = Str::random(64);
            $change = $locked->pendingEmailChanges()->create([
                'new_email' => $newEmail,
                'token_digest' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(60),
            ]);

            $this->audit($actor, $locked, 'staff_email_change_requested', $reason, $authority, [
                'new_email_domain' => Str::after($newEmail, '@'),
            ]);

            DB::afterCommit(function () use ($locked, $change, $token, $newEmail): void {
                try {
                    $locked->notify(new EmailChangeAlertNotification($newEmail));
                    Notification::route('mail', $newEmail)->notify(new PendingEmailChangeNotification($change, $token));
                } catch (Throwable $exception) {
                    $this->recordMailFailure($locked, 'staff_email_change', $change, $exception);
                }
            });

            return $change;
        });
    }

    private function authorizeAdministrator(User $actor): void
    {
        if (! $actor->hasRole(User::StaffRoleSystemSuperAdmin) || ! $actor->canAuthenticate()) {
            throw new AuthorizationException('Only an active System Administrator may manage Staff access.');
        }
    }

    private function validateReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'Enter a specific reason of at least 10 characters.',
            ]);
        }
    }

    private function lockAdministrators(): void
    {
        User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleSystemSuperAdmin))
            ->orderBy('users.id')
            ->lockForUpdate()
            ->get(['users.id']);
    }

    private function activeAdministratorCount(): int
    {
        return User::query()
            ->where('status', User::StatusActive)
            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleSystemSuperAdmin))
            ->count();
    }

    /** @param array<string, mixed> $extra */
    private function audit(User $actor, User $target, string $event, string $reason, ?string $authority, array $extra): void
    {
        activity()
            ->performedOn($target)
            ->causedBy($actor)
            ->event($event)
            ->withProperties([
                'reason' => trim($reason),
                'authority' => filled($authority) ? trim((string) $authority) : '',
                ...$extra,
            ])
            ->log(str($event)->replace('_', ' ')->headline()->toString());
    }

    private function notifyAccessChangeAfterCommit(User $target, string $summary): void
    {
        DB::afterCommit(function () use ($target, $summary): void {
            try {
                $target->notify(new AccountAccessChangedNotification($summary));
            } catch (Throwable $exception) {
                $this->recordMailFailure($target, 'staff_access_change', $target, $exception);
            }
        });
    }

    private function recordMailFailure(User $user, string $type, Model $record, Throwable $exception): void
    {
        OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainNotifications,
            'integration' => OperationalEvent::IntegrationMail,
            'channel' => OperationalEvent::ChannelEmail,
            'direction' => OperationalEvent::DirectionOutbound,
            'event_type' => $type,
            'user_id' => $user->id,
            'status' => OperationalEvent::StatusFailed,
            'occurred_at' => now(),
            'failed_at' => now(),
            'related_record_type' => $record::class,
            'related_record_id' => $record->getKey(),
            'diagnostics' => ['exception' => $exception::class],
        ]);
    }
}
