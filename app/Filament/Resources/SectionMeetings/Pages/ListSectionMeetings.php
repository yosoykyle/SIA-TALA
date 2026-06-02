<?php

namespace App\Filament\Resources\SectionMeetings\Pages;

use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSectionMeetings extends ListRecords
{
    protected static string $resource = SectionMeetingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
