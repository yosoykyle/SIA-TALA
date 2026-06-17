<?php

namespace App\Policies;

use App\Models\ExamAccessAccommodation;
use App\Models\User;

class ExamAccessAccommodationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('approve-promissory-notes');
    }

    public function view(User $user, ExamAccessAccommodation $examAccessAccommodation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('approve-promissory-notes');
    }

    public function update(User $user, ExamAccessAccommodation $examAccessAccommodation): bool
    {
        return false;
    }

    public function approve(User $user, ExamAccessAccommodation $examAccessAccommodation): bool
    {
        return $user->can('approve-promissory-notes')
            && $examAccessAccommodation->status === ExamAccessAccommodation::StatusPending;
    }

    public function reject(User $user, ExamAccessAccommodation $examAccessAccommodation): bool
    {
        return $this->approve($user, $examAccessAccommodation);
    }

    public function revoke(User $user, ExamAccessAccommodation $examAccessAccommodation): bool
    {
        return $user->can('approve-promissory-notes')
            && $examAccessAccommodation->status === ExamAccessAccommodation::StatusApproved;
    }

    public function delete(User $user, ExamAccessAccommodation $examAccessAccommodation): bool
    {
        return false;
    }

    public function restore(User $user, ExamAccessAccommodation $examAccessAccommodation): bool
    {
        return false;
    }

    public function forceDelete(User $user, ExamAccessAccommodation $examAccessAccommodation): bool
    {
        return false;
    }
}
