<?php

namespace App\Actions\StudentLifecycle;

use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateHold
{
    /** @param array<string,mixed> $data */
    public function execute(StudentProfile $studentProfile, array $data, User $actor): Hold
    {
        $holdType = (string) ($data['hold_type'] ?? '');

        if (! $this->ownsType($actor, $holdType)) {
            throw new AuthorizationException('The current office does not own this hold type.');
        }

        foreach (['blocking_level', 'reason', 'resolution_requirement'] as $required) {
            if (blank($data[$required] ?? null)) {
                throw new RuntimeException("Hold field [$required] is required.");
            }
        }

        return DB::transaction(fn (): Hold => Hold::query()->create([
            ...$data,
            'student_profile_id' => $studentProfile->id,
            'created_by' => $actor->id,
            'status' => Hold::StatusActive,
            'effective_at' => $data['effective_at'] ?? now(),
        ]), attempts: 3);
    }

    private function ownsType(User $actor, string $holdType): bool
    {
        if ($actor->hasRole(User::StaffRoleSystemSuperAdmin)) {
            return true;
        }
        if ($holdType === Hold::TypeFinancial) {
            return $actor->hasRole(User::StaffRoleAccounting);
        }
        if (in_array($holdType, [Hold::TypeAcademicDeficit, Hold::TypePrerequisite], true)) {
            return $actor->hasAnyRole([User::StaffRoleAcademicHead, User::StaffRoleRegistrar]);
        }

        return $actor->hasRole(User::StaffRoleRegistrar);
    }
}
