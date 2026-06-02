<?php

namespace App\Filament\Resources\DocumentUploads\Pages;

use App\Filament\Resources\DocumentUploads\DocumentUploadResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDocumentUpload extends ViewRecord
{
    protected static string $resource = DocumentUploadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
