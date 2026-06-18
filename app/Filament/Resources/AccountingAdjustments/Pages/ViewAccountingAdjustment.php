<?php

namespace App\Filament\Resources\AccountingAdjustments\Pages;

use App\Filament\Resources\AccountingAdjustments\AccountingAdjustmentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAccountingAdjustment extends ViewRecord
{
    protected static string $resource = AccountingAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
