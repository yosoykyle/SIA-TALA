<?php

namespace App\Filament\Resources\InstallmentPolicies\Pages;

use App\Filament\Resources\InstallmentPolicies\InstallmentPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstallmentPolicies extends ListRecords
{
    protected static string $resource = InstallmentPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
