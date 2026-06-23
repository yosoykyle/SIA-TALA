<?php

namespace App\Filament\Resources\GradeSubmissionPackages\Pages;

use App\Filament\Resources\GradeSubmissionPackages\GradeSubmissionPackageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGradeSubmissionPackages extends ListRecords
{
    protected static string $resource = GradeSubmissionPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
