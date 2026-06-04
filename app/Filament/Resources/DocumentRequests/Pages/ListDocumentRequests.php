<?php

namespace App\Filament\Resources\DocumentRequests\Pages;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDocumentRequests extends ListRecords
{
    protected static string $resource = DocumentRequestResource::class;
}
