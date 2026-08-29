<?php

namespace App\Filament\Resources\PublicNotices\Pages;

use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPublicNotices extends ListRecords
{
    protected static string $resource = PublicNoticeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add notice')];
    }
}
