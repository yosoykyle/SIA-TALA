<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Pages;

use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;

class EditFacultyTermLoadOverride extends EditRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = FacultyTermLoadOverrideResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-load-exception-form'];
    }

    public function getTitle(): string
    {
        return 'Edit load exception';
    }

    public function getBreadcrumb(): string
    {
        return 'Edit load exception';
    }

    public function getSubheading(): string
    {
        return 'Update the approved teaching-unit limit for this teacher.';
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction()->label('Cancel'),
            $this->getSaveFormAction()->label('Save changes'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->termPlanningFormData($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('View details')
                ->url(fn (): string => $this->getResourceUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
