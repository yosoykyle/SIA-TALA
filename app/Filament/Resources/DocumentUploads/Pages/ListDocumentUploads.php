<?php

namespace App\Filament\Resources\DocumentUploads\Pages;

use App\Filament\Resources\DocumentUploads\DocumentUploadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentUploads extends ListRecords
{
    protected static string $resource = DocumentUploadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
