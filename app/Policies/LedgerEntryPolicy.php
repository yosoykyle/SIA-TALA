<?php

namespace App\Policies;

use App\Models\LedgerEntry;
use App\Models\User;

class LedgerEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'process-payments',
            'create-assessments',
        ]);
    }

    public function view(User $user, LedgerEntry $ledgerEntry): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LedgerEntry $ledgerEntry): bool
    {
        return false;
    }

    public function delete(User $user, LedgerEntry $ledgerEntry): bool
    {
        return false;
    }

    public function restore(User $user, LedgerEntry $ledgerEntry): bool
    {
        return false;
    }

    public function forceDelete(User $user, LedgerEntry $ledgerEntry): bool
    {
        return false;
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
