<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Pages;

use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFacultyTermLoadOverrides extends ListRecords
{
    use HasTermPlanningContext;

    protected static string $resource = FacultyTermLoadOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
