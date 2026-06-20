<?php

namespace App\Filament\Resources\AdmissionCapacityPlans\Pages;

use App\Filament\Resources\AdmissionCapacityPlans\AdmissionCapacityPlanResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdmissionCapacityPlan extends ViewRecord
{
    protected static string $resource = AdmissionCapacityPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
