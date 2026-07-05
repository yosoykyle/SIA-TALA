<?php

namespace App\Filament\Resources\FinancialAccommodations\Pages;

use App\Filament\Resources\FinancialAccommodations\FinancialAccommodationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFinancialAccommodation extends ViewRecord
{
    protected static string $resource = FinancialAccommodationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
