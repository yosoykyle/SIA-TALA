<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\TermOverview;
use App\Models\User;

/**
 * Provides the role-conditional root breadcrumb for academic setup resources.
 */
trait HasAcademicSetupBreadcrumbs
{
    /** @return array<string, string> */
    protected function academicSetupParentBreadcrumb(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return [AcademicApprovals::getUrl() => 'Academic Oversight'];
        }

        return [TermOverview::getUrl(['chooseTerm' => true]) => 'Term planning'];
    }
}
