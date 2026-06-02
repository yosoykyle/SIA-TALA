<?php

namespace App\Filament\Resources\ScheduleChanges\Pages;

use App\Filament\Resources\ScheduleChanges\ScheduleChangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScheduleChanges extends ListRecords
{
    protected static string $resource = ScheduleChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
