<?php

namespace App\Filament\Resources\FeeTemplates\Pages;

use App\Filament\Resources\FeeTemplates\FeeTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeeTemplates extends ListRecords
{
    protected static string $resource = FeeTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
