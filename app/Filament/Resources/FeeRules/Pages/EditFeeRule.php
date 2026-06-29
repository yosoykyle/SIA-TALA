<?php

namespace App\Filament\Resources\FeeRules\Pages;

use App\Filament\Resources\FeeRules\FeeRuleResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFeeRule extends EditRecord
{
    protected static string $resource = FeeRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
