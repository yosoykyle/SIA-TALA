<?php

namespace App\Filament\Resources\FeeTemplates\Pages;

use App\Filament\Resources\FeeTemplates\FeeTemplateResource;
use App\Models\FeeTemplate;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFeeTemplate extends EditRecord
{
    protected static string $resource = FeeTemplateResource::class;

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
        return FeeTemplate::normalizeScopeData($data);
    }
}
