<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Filament\Resources\Rooms\RoomResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class EditRoom extends EditRecord
{
    use HasTermPlanningContext {
        getCancelFormAction as protected contextualCancelFormAction;
        getPageClasses as protected getTermPlanningPageClasses;
    }

    #[Url]
    public bool $returnToResources = false;

    protected ?bool $hasDatabaseTransactions = true;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-room-form'];
    }

    public function getSubheading(): string
    {
        return 'Room records apply across Terms.';
    }

    protected function beforeSave(): void
    {
        $keys = array_keys($this->data['features'] ?? []);
        foreach ($this->record->features()->pluck('id') as $id) {
            if (! in_array('record-'.$id, $keys, true)) {
                throw ValidationException::withMessages(['data.features' => 'Existing equipment cannot be removed from this form. Reload the room and correct its equipment.']);
            }
        }
    }

    protected function getCancelFormAction(): Action
    {
        return $this->returnToResources ? Action::make('cancel')->label('Cancel')->color('gray')->url($this->termPlanningReturnUrl()) : $this->contextualCancelFormAction();
    }

    protected static string $resource = RoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('View details')
                ->url(fn (): string => $this->getResourceUrl('view', ['record' => $this->getRecord(), 'returnToResources' => $this->returnToResources])),
        ];
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction()->label('Cancel')->color('gray'),
            $this->getSaveFormAction()->label('Save changes'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->returnToResources ? $this->termPlanningReturnUrl() : ($this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index'));
    }
}
