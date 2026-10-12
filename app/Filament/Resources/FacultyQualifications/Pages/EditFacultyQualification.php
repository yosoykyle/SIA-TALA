<?php

namespace App\Filament\Resources\FacultyQualifications\Pages;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFacultyQualification extends EditRecord
{
    use HasTermPlanningContext;

    protected static string $resource = FacultyQualificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
