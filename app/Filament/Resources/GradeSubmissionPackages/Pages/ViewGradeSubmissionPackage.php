<?php

namespace App\Filament\Resources\GradeSubmissionPackages\Pages;

use App\Filament\Resources\GradeSubmissionPackages\GradeSubmissionPackageResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewGradeSubmissionPackage extends ViewRecord
{
    protected static string $resource = GradeSubmissionPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
