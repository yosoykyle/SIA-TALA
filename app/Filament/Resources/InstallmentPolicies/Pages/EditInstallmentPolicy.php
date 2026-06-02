<?php

namespace App\Filament\Resources\InstallmentPolicies\Pages;

use App\Filament\Resources\InstallmentPolicies\InstallmentPolicyResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditInstallmentPolicy extends EditRecord
{
    protected static string $resource = InstallmentPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
