<?php

namespace App\Filament\Resources\InstallmentPolicies\Pages;

use App\Filament\Resources\InstallmentPolicies\InstallmentPolicyResource;
use App\Models\InstallmentPolicy;
use Filament\Resources\Pages\CreateRecord;

class CreateInstallmentPolicy extends CreateRecord
{
    protected static string $resource = InstallmentPolicyResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return InstallmentPolicy::normalizeScopeData($data);
    }
}
