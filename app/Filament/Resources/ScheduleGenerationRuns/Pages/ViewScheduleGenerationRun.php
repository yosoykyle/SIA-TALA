<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewScheduleGenerationRun extends ViewRecord
{
    protected static string $resource = ScheduleGenerationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
