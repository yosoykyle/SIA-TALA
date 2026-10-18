<?php

namespace App\Support;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\TermPlanningWorkbench;
use Filament\Navigation\NavigationItem;
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

        return [
            TermPlanningWorkbench::getUrl(['termId' => $termId]) => 'Term summary',
            ...array_slice(array_values($parent), -1),
        ];
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
