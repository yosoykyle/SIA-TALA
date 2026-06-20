<?php

namespace App\Filament\Resources\AdmissionCapacityPlans\Pages;

use App\Filament\Resources\AdmissionCapacityPlans\AdmissionCapacityPlanResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdmissionCapacityPlan extends EditRecord
{
    protected static string $resource = AdmissionCapacityPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
