<?php

namespace App\Policies;

use App\Actions\Scheduling\ScheduleSolverRecoveryService;
use App\Models\ScheduleGenerationRun;
use App\Models\User;

class ScheduleGenerationRunPolicy
{
    public function __construct(private readonly ScheduleSolverRecoveryService $recoveryService) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ScheduleGenerationRun $scheduleGenerationRun): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    public function publish(User $user, ScheduleGenerationRun $scheduleGenerationRun): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    public function reviewCandidates(User $user, ScheduleGenerationRun $scheduleGenerationRun): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar);
    }

    public function retry(User $user, ScheduleGenerationRun $scheduleGenerationRun): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            && $scheduleGenerationRun->canRetrySolver();
    }

    public function recover(User $user, ScheduleGenerationRun $scheduleGenerationRun): bool
    {
        return $user->hasRole(User::StaffRoleRegistrar)
            && $this->recoveryService->canRequestRecovery($scheduleGenerationRun);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ScheduleGenerationRun $scheduleGenerationRun): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ScheduleGenerationRun $scheduleGenerationRun): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ScheduleGenerationRun $scheduleGenerationRun): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ScheduleGenerationRun $scheduleGenerationRun): bool
    {
        return false;
    }
}
