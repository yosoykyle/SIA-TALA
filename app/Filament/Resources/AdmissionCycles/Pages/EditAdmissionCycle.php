<?php

namespace App\Filament\Resources\AdmissionCycles\Pages;

use App\Actions\Admissions\ChangeAdmissionCycle;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\AdmissionCycles\RelationManagers\RequirementSetsRelationManager;
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

    /**
     * The requirement sets are embedded in the Programs & student types step (#110 item 12), not listed below the page.
     *
     * @return array<class-string>
     */
    public function getRelationManagers(): array
    {
        return [];
    }

    /**
     * No <form> wraps the wizard here, so the embedded requirement-set table can open its own modal forms
     * without nesting them inside this page's form. Save & continue calls save() directly (#110 item 11).
     */
    public function hasFormWrapper(): bool
    {
        return false;
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [];
    }

    /**
     * Livewire properties for the requirement-set table embedded in the wizard.
     *
     * @return array<string, mixed>
     */
    public function requirementSetsManagerProperties(): array
    {
        return [
            'ownerRecord' => $this->getRecord(),
            'pageClass' => static::class,
            ...RequirementSetsRelationManager::getDefaultProperties(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof AdmissionCycle) {
            $record->loadMissing('programs');
            $paths = [];

            if ($record->programs->contains(fn ($program): bool => (bool) data_get($program->getRelation('pivot'), 'accepts_first_year'))) {
                $paths[] = AdmissionCycle::PathFirstYear;
            }

            if ($record->programs->contains(fn ($program): bool => (bool) data_get($program->getRelation('pivot'), 'accepts_transferee'))) {
                $paths[] = AdmissionCycle::PathTransferee;
            }

            $data['accepted_paths'] = $paths !== []
                ? $paths
                : [AdmissionCycle::PathFirstYear, AdmissionCycle::PathTransferee];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $defaults = AdmissionCycle::defaultGuidance();

        $data['registrar_owner_id'] = $this->getRecord()->registrar_owner_id ?? auth()->id();
        $data['applicant_instructions'] = filled($this->getRecord()->applicant_instructions)
            ? $this->getRecord()->applicant_instructions
            : $defaults['applicant_instructions'];
        $data['support_contact'] = filled($this->getRecord()->support_contact)
            ? $this->getRecord()->support_contact
            : $defaults['support_contact'];
        $data['privacy_notice_reference'] = filled($this->getRecord()->privacy_notice_reference)
            ? $this->getRecord()->privacy_notice_reference
            : $defaults['privacy_notice_reference'];

        return $data;
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save & continue');
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
            ViewAction::make()
                ->label('Back to overview')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->outlined(),
            DeleteAction::make()
                ->label('Discard draft')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->outlined()
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
