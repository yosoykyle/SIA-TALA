<?php

namespace App\Filament\Resources\DocumentRequirementItems\Pages;

use App\Filament\Resources\DocumentRequirementItems\DocumentRequirementItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentRequirementItems extends ListRecords
{
    protected static string $resource = DocumentRequirementItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
