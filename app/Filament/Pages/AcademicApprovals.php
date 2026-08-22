<?php

namespace App\Filament\Pages;

use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\User;
use Filament\Pages\Page;

class AcademicApprovals extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Approvals';

    protected static ?string $title = 'Academic Approvals';

    protected string $view = 'filament.pages.academic-approvals';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false;
    }

    /**
     * @return list<array{title: string, description: string, action: string, url: string, icon: string}>
     */
    public function approvalAreas(): array
    {
        return collect([
            [
                'title' => 'Grade Review',
                'description' => 'Open the authorized academic review view without taking over the Registrar posting and release workflow.',
                'action' => 'Review grade rosters',
                'url' => GradeRosterResource::getUrl('index'),
                'icon' => 'heroicon-o-document-check',
                'available' => GradeRosterResource::canAccess(),
            ],
            [
                'title' => 'Lifecycle Exceptions',
                'description' => 'Review approved academic-status and progression evidence within the permissions assigned to Academic Head.',
                'action' => 'Review lifecycle changes',
                'url' => StudentLifecycleChangeResource::getUrl('index'),
                'icon' => 'heroicon-o-arrow-path-rounded-square',
                'available' => StudentLifecycleChangeResource::canAccess(),
            ],
        ])
            ->where('available', true)
            ->map(function (array $area): array {
                unset($area['available']);

                return $area;
            })
            ->values()
            ->all();
    }
}
