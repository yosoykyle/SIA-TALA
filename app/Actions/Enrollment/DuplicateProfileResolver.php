<?php

namespace App\Actions\Enrollment;

use App\Models\DuplicateProfileResolution;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DuplicateProfileResolver
{
    public function resolve(
        StudentProfile $duplicate,
        StudentProfile $primary,
        string $resolutionType,
        string $reason,
        User $actor,
    ): DuplicateProfileResolution {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may resolve duplicate student profiles.');
        }

        if ($duplicate->is($primary)) {
            throw ValidationException::withMessages([
                'duplicate_student_profile_id' => 'The duplicate profile cannot be the primary profile.',
            ]);
        }

        if (! in_array($resolutionType, ['LINKED_DUPLICATE', 'NOT_DUPLICATE', 'KEEP_SEPARATE'], true)) {
            throw ValidationException::withMessages([
                'resolution_type' => 'The duplicate resolution type is invalid.',
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A duplicate resolution reason is required.',
            ]);
        }

        return DB::transaction(function () use ($duplicate, $primary, $resolutionType, $reason, $actor): DuplicateProfileResolution {
            $lockedDuplicate = StudentProfile::query()->lockForUpdate()->findOrFail($duplicate->id);
            $lockedPrimary = StudentProfile::query()->lockForUpdate()->findOrFail($primary->id);

            if ($lockedPrimary->archived_at !== null || $lockedPrimary->merged_into_id !== null) {
                throw ValidationException::withMessages([
                    'primary_student_profile_id' => 'The primary profile must be active and unmerged.',
                ]);
            }

            if ($resolutionType === 'LINKED_DUPLICATE') {
                if ($lockedDuplicate->archived_at !== null || $lockedDuplicate->merged_into_id !== null) {
                    throw ValidationException::withMessages([
                        'duplicate_student_profile_id' => 'The duplicate profile was already archived or linked.',
                    ]);
                }

                $lockedDuplicate->forceFill([
                    'lifecycle_status' => StudentProfile::LifecycleArchived,
                    'archived_at' => CarbonImmutable::now(config('app.timezone')),
                    'merged_into_id' => $lockedPrimary->id,
                ])->save();
                $lockedDuplicate->user()->lockForUpdate()->firstOrFail()->forceFill([
                    'status' => User::StatusArchived,
                    'archived_at' => CarbonImmutable::now(config('app.timezone')),
                ])->save();
            }

            return DuplicateProfileResolution::query()->create([
                'duplicate_student_profile_id' => $lockedDuplicate->id,
                'primary_student_profile_id' => $lockedPrimary->id,
                'resolution_type' => $resolutionType,
                'reason' => $reason,
                'resolved_by' => $actor->id,
                'resolved_at' => CarbonImmutable::now(config('app.timezone')),
            ]);
        }, attempts: 3);
    }
}
