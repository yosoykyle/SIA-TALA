<?php

namespace App\Filament\Resources\FacultyAvailabilityChangeRequests\Pages;

use App\Filament\Resources\FacultyAvailabilityChangeRequests\FacultyAvailabilityChangeRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFacultyAvailabilityChangeRequests extends ListRecords
{
    protected static string $resource = FacultyAvailabilityChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
