<?php

namespace App\Filament\Resources\SchedulingDemands\Pages;

use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use Filament\Resources\Pages\ListRecords;

class ListSchedulingDemands extends ListRecords
{
    protected static string $resource = SchedulingDemandResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
