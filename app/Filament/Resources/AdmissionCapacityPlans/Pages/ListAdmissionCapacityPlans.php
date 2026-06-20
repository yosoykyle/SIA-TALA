<?php

namespace App\Filament\Resources\AdmissionCapacityPlans\Pages;

use App\Filament\Resources\AdmissionCapacityPlans\AdmissionCapacityPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdmissionCapacityPlans extends ListRecords
{
    protected static string $resource = AdmissionCapacityPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
