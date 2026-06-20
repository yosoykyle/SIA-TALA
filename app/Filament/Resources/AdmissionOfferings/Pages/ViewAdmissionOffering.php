<?php

namespace App\Filament\Resources\AdmissionOfferings\Pages;

use App\Filament\Resources\AdmissionOfferings\AdmissionOfferingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdmissionOffering extends ViewRecord
{
    protected static string $resource = AdmissionOfferingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
