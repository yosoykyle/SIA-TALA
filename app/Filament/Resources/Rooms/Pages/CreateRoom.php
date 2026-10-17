<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Filament\Resources\Rooms\RoomResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Alignment;

class CreateRoom extends CreateRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = RoomResource::class;

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

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction()->label('Cancel')->color('gray'),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()->label('Save & add another')->color('gray')] : []),
            $this->getCreateFormAction()->label('Add room'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getRedirectUrl(): string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
