<?php

namespace App\Filament\Resources\FacultyQualifications\Pages;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateFacultyQualification extends CreateRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = FacultyQualificationResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-qualification-form'];
    }

    public function getTitle(): string
    {
        return 'Add qualification';
    }

    public function getBreadcrumb(): string
    {
        return 'Add qualification';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Add qualification');
    }

    protected function getRedirectUrl(): string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
