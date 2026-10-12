<?php

namespace App\Filament\Resources\SchedulingDemands\Pages;

use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Support\HasTermPlanningContext;
use Filament\Resources\Pages\ViewRecord;

class ViewSchedulingDemand extends ViewRecord
{
    use HasTermPlanningContext;

    protected static string $resource = SchedulingDemandResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
