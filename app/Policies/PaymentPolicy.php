<?php

namespace App\Policies;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::StaffRoleAccounting);
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
        if (! $this->isVerifiedPostedPayment($payment)) {
            return false;
        }

        return $user->hasRole(User::StaffRoleAccounting)
            || $payment->studentProfile()->where('user_id', $user->id)->exists()
            || $payment->termAccount()->where('credential_user_id', $user->id)->exists();
    }

    public function mapOfficialReceipt(User $user, Payment $payment): bool
    {
        return $user->canProcessPayments()
            && $this->isVerifiedPostedPayment($payment)
            && blank($payment->or_number);
    }

    private function isVerifiedPostedPayment(Payment $payment): bool
    {
        if ($payment->evidence_status !== 'verified'
            || ($payment->state !== null && $payment->state !== Payment::StatePosted)) {
            return false;
        }

        $ledgerEntry = $payment->ledgerEntry;

        if ($ledgerEntry instanceof LedgerEntry && $ledgerEntry->state === 'posted') {
            return true;
        }

        return $payment->term_account_id !== null
            && $payment->allocations()->exists();
    }
}
