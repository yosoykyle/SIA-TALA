<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Pages;

use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Models\Term;
use App\Support\HasTermPlanningContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListFacultyTermLoadOverrides extends ListRecords
{
    use HasTermPlanningContext {
        getHeader as protected getTermPlanningHeader;
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = FacultyTermLoadOverrideResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-load-exceptions-list'];
    }

    public function getHeader(): ?View
    {
        if (! TermPlanningWorkbench::canAccess()) {
            return $this->getTermPlanningHeader();
        }

        return view('filament.resources.faculty-term-load-overrides.list-header', [
            'term' => $this->termId === null ? null : Term::query()->with('academicYear')->find($this->termId),
            'navigation' => $this->getWorkspaceNavigation(),
        ]);
    }

    public function getSubheading(): string
    {
        return $this->termId === null
            ? 'Record approved exceptions to each Term’s teaching-load limit.'
            : 'Record approved exceptions to this Term’s teaching-load limit.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add load exception')
                ->icon('heroicon-o-plus')
                ->url(fn (): string => $this->getResourceUrl('create')),
        ];
    }
}
