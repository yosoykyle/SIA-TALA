<?php

namespace App\Policies;

use App\Models\PaymentScheduleRow;
use App\Models\User;

class PaymentScheduleRowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleAccounting,
            User::StaffRoleRegistrar,
        ]);
    }

    public function view(User $user, PaymentScheduleRow $paymentScheduleRow): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PaymentScheduleRow $paymentScheduleRow): bool
    {
        return false;
    }

    public function delete(User $user, PaymentScheduleRow $paymentScheduleRow): bool
    {
        return false;
    }

    public function restore(User $user, PaymentScheduleRow $paymentScheduleRow): bool
    {
        return false;
    }

    public function forceDelete(User $user, PaymentScheduleRow $paymentScheduleRow): bool
    {
        return false;
    }
}
