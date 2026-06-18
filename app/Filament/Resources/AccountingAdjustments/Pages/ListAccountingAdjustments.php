<?php

namespace App\Filament\Resources\AccountingAdjustments\Pages;

use App\Filament\Resources\AccountingAdjustments\AccountingAdjustmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountingAdjustments extends ListRecords
{
    protected static string $resource = AccountingAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Post Adjustment'),
        ];
    }
}
