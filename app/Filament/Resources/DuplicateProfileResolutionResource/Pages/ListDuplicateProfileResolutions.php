<?php

namespace App\Filament\Resources\DuplicateProfileResolutionResource\Pages;

use App\Filament\Resources\DuplicateProfileResolutionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDuplicateProfileResolutions extends ListRecords
{
    protected static string $resource = DuplicateProfileResolutionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
