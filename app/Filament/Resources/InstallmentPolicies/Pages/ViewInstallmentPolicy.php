<?php

namespace App\Filament\Resources\InstallmentPolicies\Pages;

use App\Filament\Resources\InstallmentPolicies\InstallmentPolicyResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInstallmentPolicy extends ViewRecord
{
    protected static string $resource = InstallmentPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
