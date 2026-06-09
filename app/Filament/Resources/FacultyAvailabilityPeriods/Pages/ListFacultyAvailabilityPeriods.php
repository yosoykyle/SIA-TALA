<?php

namespace App\Filament\Resources\FacultyAvailabilityPeriods\Pages;

use App\Filament\Resources\FacultyAvailabilityPeriods\FacultyAvailabilityPeriodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFacultyAvailabilityPeriods extends ListRecords
{
    protected static string $resource = FacultyAvailabilityPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Open Availability Period'),
        ];
    }
}
