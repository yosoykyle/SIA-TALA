<?php

namespace App\Filament\Resources\SectionDeliveryGroups\Pages;

use App\Filament\Resources\SectionDeliveryGroups\SectionDeliveryGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSectionDeliveryGroups extends ListRecords
{
    protected static string $resource = SectionDeliveryGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
