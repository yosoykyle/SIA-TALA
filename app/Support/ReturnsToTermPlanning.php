<?php

namespace App\Support;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\Terms\Pages\EditTerm;
use App\Filament\Resources\Terms\Pages\ViewTerm;
use App\Filament\Resources\Terms\TermResource;
use App\Models\Term;
use App\Models\User;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Url;

trait ReturnsToTermPlanning
{
    use HasTermPlanningShell;

    /** @return array<NavigationItem> */
    public function getWorkspaceNavigation(): array
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
        if ($termId === null) {
            return TermOverview::getUrl(['chooseTerm' => true]);
        }

        return TermPlanningWorkbench::getUrl(['termId' => $termId, 'viewTab' => $this->termPlanningReturnTab(), ...($this->termPlanningReturnTab() === 'resources' ? ['resourceView' => in_array($this->resourceView, ['faculty', 'rooms'], true) ? $this->resourceView : 'faculty'] : [])]);
    }

    /** @return array<string> */
    public function getBreadcrumbs(): array
    {
        $termId = $this->termPlanningContextTermId();
        $breadcrumbs = [
            ...(auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? [AcademicApprovals::getUrl(['termId' => $termId]) => 'Academic oversight'] : []),
            TermOverview::getUrl(['chooseTerm' => true]) => 'Term planning',
        ];

        if ($termId !== null) {
            $breadcrumbs[TermOverview::getUrl(['termId' => $termId])] = Term::query()->find($termId)?->typeLabel() ?? 'Overview';
            if ($this->termPlanningReturnTab() !== 'plan') {
                $breadcrumbs[$this->termPlanningReturnUrl($termId)] = match ($this->termPlanningReturnTab()) {
                    'overview' => 'Teaching calendar',
                    'classes' => 'Classes',
                    'resources' => $this->resourceView === 'rooms' ? 'Rooms' : 'Faculty availability',
                    default => 'Timetable',
                };
            }
        }

        if ($this instanceof EditTerm) {
            $breadcrumbs[TermResource::getUrl('view', ['record' => $termId, 'viewTab' => $this->viewTab, 'resourceView' => $this->resourceView])] = 'Term settings';
        }

        return [...$breadcrumbs, match (true) {
            $this instanceof EditTerm => 'Edit settings',
            $this instanceof ViewTerm => 'Term settings',
            $this instanceof CreateRecord => 'Add Term',
            default => 'Terms',
        }];
    }

    private function termPlanningReturnTab(): string
    {
        return in_array($this->viewTab, ['plan', 'overview', 'classes', 'resources', 'generate', 'published'], true)
            ? $this->viewTab : 'plan';
    }

    private function termPlanningContextTermId(): ?int
    {
        return (method_exists($this, 'getRecord') ? $this->getRecord()?->id : null) ?? ($this->termId ?? null);
    }
}
