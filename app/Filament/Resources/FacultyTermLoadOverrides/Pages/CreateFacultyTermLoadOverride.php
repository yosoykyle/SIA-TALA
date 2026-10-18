<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Pages;

use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Alignment;

class CreateFacultyTermLoadOverride extends CreateRecord
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
        return 'Add load exception';
    }

    public function getBreadcrumb(): string
    {
        return 'Add load exception';
    }

    public function getSubheading(): string
    {
        return 'Record the approved teaching-unit limit for one teacher in this Term.';
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction()->label('Cancel'),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()->label('Save & add another')] : []),
            $this->getCreateFormAction()->label('Add load exception'),
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
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->termPlanningFormData($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
