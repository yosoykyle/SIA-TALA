<?php

namespace App\Policies;

use App\Models\PaymentAttempt;
use App\Models\User;

class PaymentAttemptPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'process-payments',
        ]);
    }

    public function view(User $user, PaymentAttempt $paymentAttempt): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PaymentAttempt $paymentAttempt): bool
    {
        return false;
    }

    public function delete(User $user, PaymentAttempt $paymentAttempt): bool
    {
        return false;
    }

    public function restore(User $user, PaymentAttempt $paymentAttempt): bool
    {
        return false;
    }

    public function forceDelete(User $user, PaymentAttempt $paymentAttempt): bool
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
