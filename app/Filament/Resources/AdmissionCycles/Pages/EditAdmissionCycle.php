<?php

namespace App\Filament\Resources\AdmissionCycles\Pages;

use App\Actions\Admissions\ChangeAdmissionCycle;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AdmissionCycle;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

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
        return 'Edit cycle · '.$this->getRecord()->label;
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
                ->modalWidth(Width::Large)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->modalHeading('Discard cycle draft?')
                ->modalDescription('Permanently removes this unpublished draft cycle and any draft requirement sets. Published cycles and cycles with applications or published requirement sets cannot be discarded.')
                ->modalSubmitActionLabel('Discard draft')
                ->using(function (AdmissionCycle $record): bool {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(ChangeAdmissionCycle::class)->discardDraft($record, $actor);

                    return true;
                }),
        ];
    }
}
