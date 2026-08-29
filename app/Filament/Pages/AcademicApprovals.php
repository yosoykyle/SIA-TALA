<?php

namespace App\Filament\Pages;

use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\User;
use Filament\Pages\Page;

class AcademicApprovals extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Academic Oversight';

    protected static ?string $title = 'Academic Oversight';

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
                'title' => 'Catalog & Curricula',
                'description' => 'Review authoritative Programs and curricula within your academic oversight context.',
                'action' => 'Review catalog and curricula',
                'url' => CatalogCurriculaWorkbench::getUrl(),
                'icon' => 'heroicon-o-book-open',
                'available' => CatalogCurriculaWorkbench::canAccess(),
            ],
            [
                'title' => 'Academic Readiness',
                'description' => 'Inspect existing Program activation checks and resolve their stated blockers.',
                'action' => 'Review academic readiness',
                'url' => AcademicReadiness::getUrl(),
                'icon' => 'heroicon-o-academic-cap',
                'available' => AcademicReadiness::canAccess(),
            ],
            [
                'title' => 'Term Planning',
                'description' => 'Review the selected term and published planning evidence.',
                'action' => 'Review term planning',
                'url' => TermPlanningWorkbench::getUrl(),
                'icon' => 'heroicon-o-calendar-days',
                'available' => TermPlanningWorkbench::canAccess(),
            ],
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
