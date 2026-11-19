<?php

namespace App\Filament\Resources\FaqEntries\Pages;

use App\Filament\Clusters\PublicContent;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFaqEntries extends ListRecords
{
    protected static string $resource = FaqEntryResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            PublicContent::getUrl() => 'Public Content',
            'FAQ',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
