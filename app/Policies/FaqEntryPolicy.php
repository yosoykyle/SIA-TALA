<?php

namespace App\Policies;

use App\Models\FaqEntry;
use App\Models\User;

class FaqEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage-faqs');
    }

    public function view(User $user, FaqEntry $model): bool
    {
        return $user->can('manage-faqs');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-faqs');
    }

    public function update(User $user, FaqEntry $model): bool
    {
        return $user->can('manage-faqs');
    }

    public function delete(User $user, FaqEntry $model): bool
    {
        return $user->can('manage-faqs');
    }

    public function restore(User $user, FaqEntry $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, FaqEntry $model): bool
    {
        return false;
    }
}
