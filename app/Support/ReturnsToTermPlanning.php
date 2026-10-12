<?php

namespace App\Support;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Models\User;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Url;

trait ReturnsToTermPlanning
{
    /** @return array<NavigationItem> */
    public function getSubNavigation(): array
    {
        $termId = (method_exists($this, 'getRecord') ? $this->getRecord()?->id : null) ?? ($this->termId ?? null);

        return TermPlanningCluster::workspaceNavigation($termId, $this->termPlanningReturnTab());
    }

    #[Url]
    public string $viewTab = 'plan';

    #[Url]
    public string $resourceView = 'faculty';

    protected function termPlanningReturnUrl(?int $termId): string
    {
        return TermPlanningWorkbench::getUrl(['termId' => $termId, 'viewTab' => $this->termPlanningReturnTab(), ...($this->termPlanningReturnTab() === 'resources' ? ['resourceView' => in_array($this->resourceView, ['faculty', 'rooms'], true) ? $this->resourceView : 'faculty'] : [])]);
    }

    /** @return array<string> */
    public function getBreadcrumbs(): array
    {
        $termId = (method_exists($this, 'getRecord') ? $this->getRecord()?->id : null) ?? ($this->termId ?? null);
        $parent = parent::getBreadcrumbs();
        if ($termId === null) {
            return $parent;
        }

        $labels = ['plan' => 'Overview', 'overview' => 'Calendar', 'classes' => 'Sections & classes', 'resources' => 'Faculty & rooms', 'generate' => 'Timetable', 'published' => 'Timetable'];

        return [
            ...(auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? [AcademicApprovals::getUrl(['termId' => $termId]) => 'Academic Oversight'] : []),
            TermPlanningWorkbench::getUrl(['termId' => $termId]) => 'Term Planning',
            ...($this instanceof CreateRecord || $this->termPlanningReturnTab() === 'plan' ? [] : [$this->termPlanningReturnUrl($termId) => $labels[$this->termPlanningReturnTab()]]),
            ...array_slice(array_values($parent), -1),
        ];
    }

    private function termPlanningReturnTab(): string
    {
        return in_array($this->viewTab, ['plan', 'overview', 'classes', 'resources', 'generate', 'published'], true)
            ? $this->viewTab : 'plan';
    }
}
