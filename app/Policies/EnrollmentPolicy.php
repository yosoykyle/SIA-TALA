<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ]) || $this->canAny($user, [
            'approve-documents',
            'evaluate-transferees',
            'create-assessments',
            'process-payments',
        ]);
    }

    public function view(User $user, Enrollment $enrollment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Enrollment $enrollment): bool
    {
        return false;
    }

    public function delete(User $user, Enrollment $enrollment): bool
    {
        return false;
    }

    public function restore(User $user, Enrollment $enrollment): bool
    {
        return false;
    }

    public function forceDelete(User $user, Enrollment $enrollment): bool
    {
        return false;
    }

    public function assess(User $user, Enrollment $enrollment): bool
    {
        return $user->hasRole(User::StaffRoleAccounting);
    }

    public function confirmPayment(User $user, Enrollment $enrollment): bool
    {
        return $user->can('process-payments');
    }

    public function viewStatement(User $user, Enrollment $enrollment): bool
    {
        return $this->canAny($user, ['create-assessments', 'process-payments'])
            || $enrollment->studentProfile()->where('user_id', $user->id)->exists();
    }

    public function markHardCopyReceived(User $user, Enrollment $enrollment): bool
    {
        return $this->canAny($user, [
            'approve-documents',
            'evaluate-transferees',
        ]);
    }

    public function confirmPlacement(User $user, Enrollment $enrollment): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleSystemSuperAdmin,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function canAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
