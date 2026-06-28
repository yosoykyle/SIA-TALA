<?php

namespace App\Filament\Resources\PersonalDataCorrectionRequestResource\Pages;

use App\Filament\Resources\PersonalDataCorrectionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListPersonalDataCorrectionRequests extends ListRecords
{
    protected static string $resource = PersonalDataCorrectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
