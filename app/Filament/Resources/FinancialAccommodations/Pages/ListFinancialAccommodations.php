<?php

namespace App\Filament\Resources\FinancialAccommodations\Pages;

use App\Filament\Resources\FinancialAccommodations\FinancialAccommodationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFinancialAccommodations extends ListRecords
{
    protected static string $resource = FinancialAccommodationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Record Accommodation'),
        ];
    }
}
