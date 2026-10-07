<?php

namespace App\Filament\Resources\AdmissionCycles\Pages;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateAdmissionCycle extends CreateRecord
{
    protected static string $resource = AdmissionCycleResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Save cycle draft');
    }

    protected function getRedirectUrl(): string
    {
        return AdmissionCycleResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    public function getTitle(): string
    {
        return 'Prepare admission cycle';
    }

    public function getBreadcrumbs(): array
    {
        return [
            ...(AdmissionApplicationResource::canAccess() ? [AdmissionApplicationResource::getUrl() => 'Admissions'] : []),
            AdmissionCycleResource::getUrl() => 'Admission cycles',
            'Prepare cycle',
        ];
    }
}
