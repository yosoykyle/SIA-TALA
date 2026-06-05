<?php

namespace App\Filament\Resources\FeeTemplates\Pages;

use App\Filament\Resources\FeeTemplates\FeeTemplateResource;
use App\Models\FeeTemplate;
use Filament\Resources\Pages\CreateRecord;

class CreateFeeTemplate extends CreateRecord
{
    protected static string $resource = FeeTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return FeeTemplate::normalizeScopeData($data);
    }
}
