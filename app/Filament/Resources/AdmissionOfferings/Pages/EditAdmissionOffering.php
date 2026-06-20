<?php

namespace App\Filament\Resources\AdmissionOfferings\Pages;

use App\Filament\Resources\AdmissionOfferings\AdmissionOfferingResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdmissionOffering extends EditRecord
{
    protected static string $resource = AdmissionOfferingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
