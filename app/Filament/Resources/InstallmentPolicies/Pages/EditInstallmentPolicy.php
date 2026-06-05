<?php

namespace App\Filament\Resources\InstallmentPolicies\Pages;

use App\Filament\Resources\InstallmentPolicies\InstallmentPolicyResource;
use App\Models\InstallmentPolicy;
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return InstallmentPolicy::normalizeScopeData($data);
    }
}
