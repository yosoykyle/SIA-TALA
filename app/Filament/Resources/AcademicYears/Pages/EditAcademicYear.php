<?php

namespace App\Filament\Resources\AcademicYears\Pages;

use App\Filament\Concerns\HasAcademicSetupBreadcrumbs;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAcademicYear extends EditRecord
{
    use HasAcademicSetupBreadcrumbs;

    protected static string $resource = AcademicYearResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            ...$this->academicSetupParentBreadcrumb(),
            AcademicYearResource::getUrl('index') => 'Academic Years',
            AcademicYearResource::getUrl('view', ['record' => $this->getRecord()]) => $this->getRecord()->label,
            'Edit',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
