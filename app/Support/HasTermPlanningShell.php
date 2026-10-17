<?php

namespace App\Support;

use App\Filament\Pages\TermPlanningWorkbench;
use App\Models\Term;
use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\View\View;

trait HasTermPlanningShell
{
    /** @return array<string> */
    public function getPageClasses(): array
    {
        if (! TermPlanningWorkbench::canAccess()) {
            return parent::getPageClasses();
        }

        return [...parent::getPageClasses(), 'tala-term-workspace', 'tala-term-record-workspace'];
    }

    /** @return array<NavigationItem> */
    public function getSubNavigation(): array
    {
        return [];
    }

    public function getHeader(): ?View
    {
        if (! TermPlanningWorkbench::canAccess()) {
            return parent::getHeader();
        }

        $termId = $this->termPlanningContextTermId();

        return view('filament.pages.partials.term-record-header', [
            'term' => $termId === null ? null : Term::query()->with('academicYear')->find($termId),
            'navigation' => $this->getWorkspaceNavigation(),
        ]);
    }
}
