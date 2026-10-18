<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Filament\Resources\Rooms\RoomResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Url;

class ViewRoom extends ViewRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = RoomResource::class;

    #[Url]
    public bool $returnToResources = false;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-room-detail'];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Edit room')->authorize('update')
                ->url(fn (): string => $this->getResourceUrl('edit', ['record' => $this->getRecord(), 'returnToResources' => $this->returnToResources])),
        ];
    }
}
