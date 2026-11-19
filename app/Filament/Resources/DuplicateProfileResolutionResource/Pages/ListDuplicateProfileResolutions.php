<?php

namespace App\Filament\Resources\DuplicateProfileResolutionResource\Pages;

use App\Filament\Concerns\HasAcademicOversightReturn;
use App\Filament\Resources\DuplicateProfileResolutionResource;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDuplicateProfileResolutions extends ListRecords
{
    use HasAcademicOversightReturn;

    protected static string $resource = DuplicateProfileResolutionResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        if ($this->isAcademicHeadReader()) {
            return [
                $this->oversightReturnUrl() => 'Academic Oversight',
                'Duplicate resolutions',
            ];
        }

        return [
            EnrollmentResource::getUrl('index') => 'Students & Enrollment',
            'Duplicate resolutions',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...($this->isAcademicHeadReader() ? [$this->returnToOversightAction()] : []),
            CreateAction::make(),
        ];
    }
}
