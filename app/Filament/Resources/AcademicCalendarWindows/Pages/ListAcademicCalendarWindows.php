<?php

namespace App\Filament\Resources\AcademicCalendarWindows\Pages;

use App\Filament\Concerns\HasAcademicSetupBreadcrumbs;
use App\Filament\Resources\AcademicCalendarWindows\AcademicCalendarWindowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcademicCalendarWindows extends ListRecords
{
    use HasAcademicSetupBreadcrumbs;

    protected static string $resource = AcademicCalendarWindowResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            ...$this->academicSetupParentBreadcrumb(),
            'Academic Calendar Windows',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
