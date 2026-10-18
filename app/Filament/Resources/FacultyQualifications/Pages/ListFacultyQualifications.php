<?php

namespace App\Filament\Resources\FacultyQualifications\Pages;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFacultyQualifications extends ListRecords
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = FacultyQualificationResource::class;

    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-qualifications-list'];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add qualification')->icon('heroicon-o-plus'),
        ];
    }
}
