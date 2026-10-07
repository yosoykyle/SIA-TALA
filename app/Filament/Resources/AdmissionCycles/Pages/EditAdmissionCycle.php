<?php

namespace App\Filament\Resources\AdmissionCycles\Pages;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdmissionCycle extends EditRecord
{
    protected static string $resource = AdmissionCycleResource::class;

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save and exit');
    }

    protected function getRedirectUrl(): ?string
    {
        return AdmissionCycleResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    public function getTitle(): string
    {
        return 'Edit cycle — '.$this->getRecord()->label;
    }

    public function getBreadcrumbs(): array
    {
        return [
            ...(AdmissionApplicationResource::canAccess() ? [AdmissionApplicationResource::getUrl() => 'Admissions'] : []),
            AdmissionCycleResource::getUrl() => 'Admission cycles',
            'Edit draft cycle',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()->label('Discard draft')
                ->modalHeading('Discard cycle draft?')
                ->modalDescription('This removes the unreferenced cycle draft. Published cycles and drafts with applications, requirements or retained events cannot be discarded.')
                ->modalSubmitActionLabel('Discard draft'),
        ];
    }
}
