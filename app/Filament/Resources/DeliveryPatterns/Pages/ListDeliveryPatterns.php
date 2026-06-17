<?php

namespace App\Filament\Resources\DeliveryPatterns\Pages;

use App\Filament\Resources\DeliveryPatterns\DeliveryPatternResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryPatterns extends ListRecords
{
    protected static string $resource = DeliveryPatternResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
