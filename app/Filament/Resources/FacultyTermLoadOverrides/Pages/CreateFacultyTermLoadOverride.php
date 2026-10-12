<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Pages;

use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Support\HasTermPlanningContext;
use Filament\Resources\Pages\CreateRecord;

class CreateFacultyTermLoadOverride extends CreateRecord
{
    use HasTermPlanningContext;

    protected static string $resource = FacultyTermLoadOverrideResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->termPlanningFormData($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
