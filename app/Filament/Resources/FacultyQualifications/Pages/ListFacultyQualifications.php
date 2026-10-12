<?php

namespace App\Filament\Resources\FacultyQualifications\Pages;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFacultyQualifications extends ListRecords
{
    use HasTermPlanningContext;

    protected static string $resource = FacultyQualificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
