<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\ImportBatches\ImportBatchDownloadActions;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use Filament\Resources\Pages\ViewRecord;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportBatchDownloadActions::validationFindings(),
            ImportBatchDownloadActions::source(),
        ];
    }
}
