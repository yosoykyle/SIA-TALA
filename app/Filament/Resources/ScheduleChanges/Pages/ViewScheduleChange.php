<?php

namespace App\Filament\Resources\ScheduleChanges\Pages;

use App\Filament\Resources\ScheduleChanges\ScheduleChangeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewScheduleChange extends ViewRecord
{
    protected static string $resource = ScheduleChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
