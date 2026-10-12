<?php

namespace App\Filament\Resources\SectionMeetings\Pages;

use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Support\HasTermPlanningContext;
use Filament\Resources\Pages\ListRecords;

class ListSectionMeetings extends ListRecords
{
    use HasTermPlanningContext;

    protected static string $resource = SectionMeetingResource::class;

    public function getTitle(): string
    {
        return 'Published Timetable';
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
