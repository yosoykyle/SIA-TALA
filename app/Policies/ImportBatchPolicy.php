<?php

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\User;

class ImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }

    public function view(User $user, ImportBatch $importBatch): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function manage(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    public function update(User $user, ImportBatch $importBatch): bool
    {
        return $this->manage($user);
    }

    public function download(User $user, ImportBatch $importBatch): bool
    {
        return $this->view($user, $importBatch);
    }

    public function delete(User $user, ImportBatch $importBatch): bool
    {
        return false;
    }

    public function restore(User $user, ImportBatch $importBatch): bool
    {
        return false;
    }

    public function forceDelete(User $user, ImportBatch $importBatch): bool
    {
        return false;
    }
}
