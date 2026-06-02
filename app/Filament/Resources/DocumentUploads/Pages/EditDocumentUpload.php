<?php

namespace App\Filament\Resources\DocumentUploads\Pages;

use App\Filament\Resources\DocumentUploads\DocumentUploadResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDocumentUpload extends EditRecord
{
    protected static string $resource = DocumentUploadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
