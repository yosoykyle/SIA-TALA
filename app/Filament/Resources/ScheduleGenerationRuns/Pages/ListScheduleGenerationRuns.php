<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Pages;

use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use Filament\Resources\Pages\ListRecords;

class ListScheduleGenerationRuns extends ListRecords
{
    protected static string $resource = ScheduleGenerationRunResource::class;
}
