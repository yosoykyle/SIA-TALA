<?php

namespace App\Filament\Resources\SectionMeetings\Pages;

use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Support\HasTermPlanningContext;
use Filament\Resources\Pages\ViewRecord;

class ViewSectionMeeting extends ViewRecord
{
    use HasTermPlanningContext;

    protected static string $resource = SectionMeetingResource::class;
}
