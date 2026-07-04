<?php

namespace App\Filament\Resources\CurriculumVersions\Pages;

use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCurriculumVersions extends ListRecords
{
    protected static string $resource = CurriculumVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
