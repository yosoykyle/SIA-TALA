<?php

namespace App\Filament\Resources\DeliveryPatterns\Pages;

use App\Filament\Resources\DeliveryPatterns\DeliveryPatternResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDeliveryPattern extends ViewRecord
{
    protected static string $resource = DeliveryPatternResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
