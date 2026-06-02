<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScheduleGenerationRuns extends ListRecords
{
    protected static string $resource = ScheduleGenerationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
