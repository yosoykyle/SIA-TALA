<?php

namespace App\Filament\Resources\FacultyQualifications\Pages;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Support\HasTermPlanningContext;
use Filament\Resources\Pages\CreateRecord;

class CreateFacultyQualification extends CreateRecord
{
    use HasTermPlanningContext;

    protected static string $resource = FacultyQualificationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
