<?php

namespace App\Filament\Resources\SectionMeetings\Pages;

use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Support\HasTermPlanningContext;
use Filament\Resources\Pages\ListRecords;

class ListSectionMeetings extends ListRecords
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = SectionMeetingResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-planning-source-list'];
    }

    public function getTitle(): string
    {
        return 'Official meetings';
    }

    public function getSubheading(): string
    {
        return $this->termPlanningContextDescription().'These active official meetings are the timetable projected to students, faculty, enrollment, and generated records.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
