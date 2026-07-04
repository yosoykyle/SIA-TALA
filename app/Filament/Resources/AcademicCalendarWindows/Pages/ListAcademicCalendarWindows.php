<?php

namespace App\Filament\Resources\AcademicCalendarWindows\Pages;

use App\Filament\Resources\AcademicCalendarWindows\AcademicCalendarWindowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcademicCalendarWindows extends ListRecords
{
    protected static string $resource = AcademicCalendarWindowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
