<?php

namespace App\Filament\Resources\DocumentUploads\Pages;

use App\Filament\Resources\DocumentUploads\DocumentUploadResource;
use Filament\Resources\Pages\ListRecords;

class ListDocumentUploads extends ListRecords
{
    protected static string $resource = DocumentUploadResource::class;
}
