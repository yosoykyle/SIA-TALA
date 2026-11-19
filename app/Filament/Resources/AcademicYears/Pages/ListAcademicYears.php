<?php

namespace App\Filament\Resources\AcademicYears\Pages;

use App\Filament\Concerns\HasAcademicSetupBreadcrumbs;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcademicYears extends ListRecords
{
    use HasAcademicSetupBreadcrumbs;

    protected static string $resource = AcademicYearResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            ...$this->academicSetupParentBreadcrumb(),
            'Academic Years',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
