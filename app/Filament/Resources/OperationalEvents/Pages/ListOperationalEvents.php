<?php

namespace App\Filament\Resources\OperationalEvents\Pages;

use App\Filament\Resources\OperationalEvents\OperationalEventResource;
use Filament\Resources\Pages\ListRecords;

class ListOperationalEvents extends ListRecords
{
    protected static string $resource = OperationalEventResource::class;
}
