<?php

namespace App\Filament\Resources\Sections\Pages;

use App\Actions\Scheduling\SectionPlanningService;
use App\Filament\Pages\TermClasses;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\Section;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Validation\ValidationException;

class CreateSection extends CreateRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = SectionResource::class;

    public function mount(): void
    {
        parent::mount();
        if ($this->termId !== null) {
            $this->redirect(TermClasses::getUrl(['termId' => $this->termId, 'action' => 'addClass']));
        }
    }

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-class-form'];
    }

    public function getTitle(): string
    {
        return 'Add a class';
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction()->label('Cancel'),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()->label('Save & add another')] : []),
            $this->getCreateFormAction()->label('Add class'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(SectionPlanningService::class)->prepareForSave($this->termPlanningFormData([...$data, 'state' => Section::StatePlanned]));
    }

    protected function getRedirectUrl(): string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
