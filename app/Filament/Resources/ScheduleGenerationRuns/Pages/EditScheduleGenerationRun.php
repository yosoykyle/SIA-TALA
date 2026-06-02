<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditScheduleGenerationRun extends EditRecord
{
    protected static string $resource = ScheduleGenerationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
