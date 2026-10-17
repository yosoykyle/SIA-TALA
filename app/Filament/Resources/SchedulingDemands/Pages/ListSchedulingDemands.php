<?php

namespace App\Filament\Resources\SchedulingDemands\Pages;

use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Support\HasTermPlanningContext;
use Filament\Resources\Pages\ListRecords;

class ListSchedulingDemands extends ListRecords
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = SchedulingDemandResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-planning-source-list'];
    }

    public function getTitle(): string
    {
        return 'Schedule requirements';
    }

    public function getSubheading(): string
    {
        return $this->termPlanningContextDescription().'Each row is one required course component for one section delivery group. Resolve every blocker before generating a timetable.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
