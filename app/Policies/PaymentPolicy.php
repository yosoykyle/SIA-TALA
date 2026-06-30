<?php

namespace App\Policies;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'process-payments',
        ]);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    public function restore(User $user, Payment $payment): bool
    {
        return false;
    }

    public function forceDelete(User $user, Payment $payment): bool
    {
        return false;
    }

    public function viewAcknowledgement(User $user, Payment $payment): bool
    {
        $ledgerEntry = $payment->ledgerEntry;

        if ($payment->evidence_status !== 'verified'
            || ! $ledgerEntry instanceof LedgerEntry
            || $ledgerEntry->state !== 'posted') {
            return false;
        }

        return $user->can('process-payments')
            || $payment->studentProfile()->where('user_id', $user->id)->exists();
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
