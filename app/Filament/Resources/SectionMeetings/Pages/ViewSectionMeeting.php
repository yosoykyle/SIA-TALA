<?php

namespace App\Filament\Resources\SectionMeetings\Pages;

use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSectionMeeting extends ViewRecord
{
    protected static string $resource = SectionMeetingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
