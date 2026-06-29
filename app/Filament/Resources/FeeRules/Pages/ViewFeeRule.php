<?php

namespace App\Filament\Resources\FeeRules\Pages;

use App\Filament\Resources\FeeRules\FeeRuleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFeeRule extends ViewRecord
{
    protected static string $resource = FeeRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
