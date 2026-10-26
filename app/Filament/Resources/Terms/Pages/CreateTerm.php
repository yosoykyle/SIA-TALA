<?php

namespace App\Filament\Resources\Terms\Pages;

use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\Terms\TermResource;
use App\Models\Term;
use App\Support\ReturnsToTermPlanning;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Alignment;
use Livewire\Attributes\Url;

class CreateTerm extends CreateRecord
{
    use ReturnsToTermPlanning {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = TermResource::class;

    public function getTitle(): string
    {
        return 'Add Term';
    }

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-term-record-page'];

    #[Url]
    public ?int $termId = null;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-term-form'];
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction()->label('Cancel')->color('gray'),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()->label('Save & add another')->color('gray')] : []),
            $this->getCreateFormAction()->label('Add Term'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')->label('Cancel and return to Term Planning')->color('gray')
            ->url($this->termPlanningReturnUrl($this->termId));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'label' => filled($data['label'] ?? null) ? $data['label'] : Term::typeOptions()[$data['type']], 'state' => Term::StateDraft, 'scheduling_slot_minutes' => 30, 'scheduling_days' => null];
    }

    protected function getRedirectUrl(): string
    {
        return TermPlanningWorkbench::getUrl(['termId' => $this->record->id, 'viewTab' => 'plan']);
    }
}
