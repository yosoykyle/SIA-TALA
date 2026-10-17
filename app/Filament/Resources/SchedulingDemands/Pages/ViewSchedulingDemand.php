<?php

namespace App\Filament\Resources\SchedulingDemands\Pages;

use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Support\HasTermPlanningContext;
use Filament\Resources\Pages\ViewRecord;

class ViewSchedulingDemand extends ViewRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = SchedulingDemandResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-planning-source-detail'];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
