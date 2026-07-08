<?php

namespace App\Policies;

use App\Models\DisposalReview;
use App\Models\User;

/**
 * TAL-92E: disposal-review audit trail policy.
 *
 * Owning contract: PRD §13.7.4 rule 7 ("disposal actions must be
 * permission-controlled"). View/create are System Super-Admin only.
 * Update/delete are always false — a written review record is an audit
 * trail entry, not an editable record (mirrors `ActivityPolicy` /
 * `OperationalEventPolicy`).
 */
class DisposalReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin);
    }

    public function view(User $user, DisposalReview $disposalReview): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::StaffRoleSystemSuperAdmin);
    }

    public function update(User $user, DisposalReview $disposalReview): bool
    {
        return false;
    }

    public function delete(User $user, DisposalReview $disposalReview): bool
    {
        return false;
    }
}
