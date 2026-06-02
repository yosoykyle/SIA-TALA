<?php

namespace App\Filament\Resources\CorVerifications\Pages;

use App\Filament\Resources\CorVerifications\CorVerificationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCorVerifications extends ListRecords
{
    protected static string $resource = CorVerificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
