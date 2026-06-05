<?php

namespace App\Actions\SystemAdministration;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAccountLifecycleService
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function archive(User $target, User $actor, string $reason, ?CarbonImmutable $archivedAt = null): User
    {
        $normalizedReason = $this->normalizeArchiveReason($reason);

        return DB::transaction(function () use ($target, $actor, $normalizedReason, $archivedAt): User {
            $lockedTarget = User::query()
                ->lockForUpdate()
                ->findOrFail($target->id);

            $this->authorize($actor, 'archiveStaffAccount', $lockedTarget);

            if ($lockedTarget->status === User::StatusArchived) {
                throw ValidationException::withMessages([
                    'status' => 'Archived staff accounts cannot be archived again.',
                ]);
            }

            $timestamp = $archivedAt ?? CarbonImmutable::now(config('app.timezone'));

            $lockedTarget->forceFill([
                'status' => User::StatusArchived,
                'archived_at' => $timestamp,
                'archived_reason' => $normalizedReason,
            ])->save();

            $lockedTarget->syncRoles([]);

            activity()
                ->performedOn($lockedTarget)
                ->causedBy($actor)
                ->event('staff_account_archived')
                ->withProperties([
                    'reason' => $normalizedReason,
                    'status_after' => User::StatusArchived,
                ])
                ->log('Staff account archived');

            return $lockedTarget->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function restore(User $target, User $actor, string $role, ?CarbonImmutable $restoredAt = null): User
    {
        $normalizedRole = $this->normalizeStaffRole($role);

        return DB::transaction(function () use ($target, $actor, $normalizedRole, $restoredAt): User {
            $lockedTarget = User::query()
                ->lockForUpdate()
                ->findOrFail($target->id);

            $this->authorize($actor, 'restoreStaffAccount', $lockedTarget);

            if ($lockedTarget->status !== User::StatusArchived) {
                throw ValidationException::withMessages([
                    'status' => 'Only archived staff accounts can be restored.',
                ]);
            }

            $timestamp = $restoredAt ?? CarbonImmutable::now(config('app.timezone'));

            $lockedTarget->forceFill([
                'status' => User::StatusActive,
                'archived_at' => null,
                'archived_reason' => null,
            ])->save();

            $lockedTarget->syncRoles([$normalizedRole]);

            activity()
                ->performedOn($lockedTarget)
                ->causedBy($actor)
                ->event('staff_account_restored')
                ->withProperties([
                    'role' => $normalizedRole,
                    'status_after' => User::StatusActive,
                    'restored_at' => $timestamp->toDateTimeString(),
                ])
                ->log('Staff account restored');

            return $lockedTarget->refresh();
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor, string $ability, User $target): void
    {
        if ($actor->can($ability, $target)) {
            return;
        }

        throw new AuthorizationException('Only authorized System Super Admin users can manage staff account lifecycle actions.');
    }

    /**
     * @throws ValidationException
     */
    private function normalizeArchiveReason(string $reason): string
    {
        $normalized = trim($reason);

        if (mb_strlen($normalized) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'An official archive reason of at least 10 characters is required.',
            ]);
        }

        return $normalized;
    }

    /**
     * @throws ValidationException
     */
    private function normalizeStaffRole(string $role): string
    {
        $normalized = trim($role);

        if (! in_array($normalized, User::staffRoleNames(), true)) {
            throw ValidationException::withMessages([
                'role' => 'Restored staff accounts require one approved staff role.',
            ]);
        }

        return $normalized;
    }
}
