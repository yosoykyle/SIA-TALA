<?php

namespace App\Filament\Resources\AdmissionOfferings\Pages;

use App\Filament\Resources\AdmissionOfferings\AdmissionOfferingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdmissionOfferings extends ListRecords
{
    protected static string $resource = AdmissionOfferingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
