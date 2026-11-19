<?php

namespace App\Filament\Resources\AcademicYears\Pages;

use App\Filament\Concerns\HasAcademicSetupBreadcrumbs;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAcademicYear extends ViewRecord
{
    use HasAcademicSetupBreadcrumbs;

    protected static string $resource = AcademicYearResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            ...$this->academicSetupParentBreadcrumb(),
            AcademicYearResource::getUrl('index') => 'Academic Years',
            $this->getRecord()->label,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
