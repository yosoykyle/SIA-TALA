<?php

namespace App\Filament\Resources\DocumentRequirementItems\Pages;

use App\Filament\Resources\DocumentRequirementItems\DocumentRequirementItemResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDocumentRequirementItem extends EditRecord
{
    protected static string $resource = DocumentRequirementItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
