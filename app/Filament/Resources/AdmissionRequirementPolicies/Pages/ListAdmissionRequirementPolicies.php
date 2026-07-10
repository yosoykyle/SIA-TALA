<?php

namespace App\Filament\Resources\AdmissionRequirementPolicies\Pages;

use App\Filament\Resources\AdmissionRequirementPolicies\AdmissionRequirementPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdmissionRequirementPolicies extends ListRecords
{
    protected static string $resource = AdmissionRequirementPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
