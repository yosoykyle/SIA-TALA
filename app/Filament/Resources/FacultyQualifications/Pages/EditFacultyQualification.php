<?php

namespace App\Filament\Resources\FacultyQualifications\Pages;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFacultyQualification extends EditRecord
{
    protected static string $resource = FacultyQualificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
