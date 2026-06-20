<?php

namespace App\Filament\Resources\AdmissionRequirementPolicies\Pages;

use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdmissionRequirementPolicy extends ViewRecord
{
    protected static string $resource = AdmissionRequirementPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
