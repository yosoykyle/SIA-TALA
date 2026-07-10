<?php

namespace App\Filament\Resources\AdmissionRequirementPolicies\Pages;

use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdmissionRequirementPolicy extends EditRecord
{
    protected static string $resource = AdmissionRequirementPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
