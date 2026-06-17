<?php

namespace App\Filament\Resources\SectionDeliveryGroups\Pages;

use App\Filament\Resources\SectionDeliveryGroups\SectionDeliveryGroupResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSectionDeliveryGroup extends ViewRecord
{
    protected static string $resource = SectionDeliveryGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
