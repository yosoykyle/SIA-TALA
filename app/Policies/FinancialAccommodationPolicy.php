<?php

namespace App\Policies;

use App\Models\FinancialAccommodation;
use App\Models\User;

class FinancialAccommodationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canProcessPayments();
    }

    public function view(User $user, FinancialAccommodation $financialAccommodation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->canProcessPayments();
    }

    public function update(User $user, FinancialAccommodation $financialAccommodation): bool
    {
        return false;
    }

    public function transition(User $user, FinancialAccommodation $financialAccommodation): bool
    {
        return $user->canProcessPayments();
    }

    public function delete(User $user, FinancialAccommodation $financialAccommodation): bool
    {
        return false;
    }

    public function restore(User $user, FinancialAccommodation $financialAccommodation): bool
    {
        return false;
    }

    public function forceDelete(User $user, FinancialAccommodation $financialAccommodation): bool
    {
        return false;
    }
}
