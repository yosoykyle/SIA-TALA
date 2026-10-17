<?php

namespace App\Filament\Resources\SectionMeetings\Pages;

use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Support\HasTermPlanningContext;
use Filament\Resources\Pages\ViewRecord;

class ViewSectionMeeting extends ViewRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = SectionMeetingResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-planning-source-detail'];
    }
}
