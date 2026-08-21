<?php

namespace App\Policies;

use App\Models\PaymentEvidenceVersion;
use App\Models\User;

class PaymentEvidenceVersionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->canAuthenticate() && $user->hasRole(User::StaffRoleAccounting);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PaymentEvidenceVersion $paymentEvidenceVersion): bool
    {
        return $user->canAuthenticate()
            && ($this->viewAny($user) || (int) $paymentEvidenceVersion->termAccount?->credential_user_id === (int) $user->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PaymentEvidenceVersion $paymentEvidenceVersion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PaymentEvidenceVersion $paymentEvidenceVersion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, PaymentEvidenceVersion $paymentEvidenceVersion): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, PaymentEvidenceVersion $paymentEvidenceVersion): bool
    {
        return false;
    }
}
