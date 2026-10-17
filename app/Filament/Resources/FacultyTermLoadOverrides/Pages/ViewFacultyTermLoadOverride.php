<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Pages;

use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Models\FacultyTermLoadOverride;
use App\Support\HasTermPlanningContext;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFacultyTermLoadOverride extends ViewRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = FacultyTermLoadOverrideResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-load-exception-detail'];
    }

    public function getTitle(): string
    {
        /** @var FacultyTermLoadOverride $record */
        $record = $this->getRecord();

        return $record->faculty?->name ?? 'Load exception';
    }

    public function getSubheading(): string
    {
        return 'Teaching-load exception for this Term.';
    }

    public function getBreadcrumb(): string
    {
        return 'Exception details';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Edit load exception'),
        ];
    }
}
