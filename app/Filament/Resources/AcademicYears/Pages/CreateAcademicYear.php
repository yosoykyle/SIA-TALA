<?php

namespace App\Filament\Resources\AcademicYears\Pages;

use App\Filament\Concerns\HasAcademicSetupBreadcrumbs;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAcademicYear extends CreateRecord
{
    use HasAcademicSetupBreadcrumbs;

    protected static string $resource = AcademicYearResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            ...$this->academicSetupParentBreadcrumb(),
            AcademicYearResource::getUrl('index') => 'Academic Years',
            'Add academic year',
        ];
    }
}
