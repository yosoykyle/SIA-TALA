<?php

namespace App\Policies;

use App\Actions\Reports\OperationalReportService;
use App\Models\User;

class OperationalReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ]);
    }

    public function view(User $user, string $reportKey): bool
    {
        return match (true) {
            $reportKey === OperationalReportService::GraduationSnapshot => $user->hasRole(User::StaffRoleRegistrar)
                || $user->hasRole(User::StaffRoleAcademicHead),
            str_starts_with($reportKey, 'registrar.') => $user->hasRole(User::StaffRoleRegistrar),
            str_starts_with($reportKey, 'accounting.') => false,
            str_starts_with($reportKey, 'academic.') => $user->hasRole(User::StaffRoleAcademicHead),
            str_starts_with($reportKey, 'audit.') => $user->hasRole(User::StaffRoleSystemSuperAdmin),
            default => false,
        };
    }

    public function export(User $user, string $reportKey): bool
    {
        return $this->view($user, $reportKey);
    }
}
