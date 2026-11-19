<?php

namespace App\Filament\Resources\PublicNotices\Pages;

use App\Filament\Clusters\PublicContent;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPublicNotices extends ListRecords
{
    protected static string $resource = PublicNoticeResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            PublicContent::getUrl() => 'Public Content',
            'Notices',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add notice')];
    }
}
