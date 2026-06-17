<?php

namespace App\Policies;

use App\Models\DeliveryPattern;
use App\Models\User;

class DeliveryPatternPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DeliveryPattern $deliveryPattern): bool
    {
        return $this->canManage($user) || $user->can('view-global-records');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DeliveryPattern $deliveryPattern): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DeliveryPattern $deliveryPattern): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, DeliveryPattern $deliveryPattern): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, DeliveryPattern $deliveryPattern): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $user->can('manage-schedules') || $user->can('manage-sections');
    }
}
