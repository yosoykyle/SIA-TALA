<?php

namespace App\Filament\Resources\Terms\Pages;

use App\Filament\Resources\Terms\TermResource;
use App\Support\ReturnsToTermPlanning;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTerm extends ViewRecord
{
    use ReturnsToTermPlanning {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = TermResource::class;

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-term-record-page'];

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-term-detail'];
    }

    public function getTitle(): string
    {
        return $this->record->label;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('returnToPlanning')->label('Open planning')
                ->color('gray')->url(fn (): string => $this->termPlanningReturnUrl($this->record->id)),
            EditAction::make()->label('Edit settings')->color('gray')->url(fn (): string => TermResource::getUrl('edit', ['record' => $this->record, 'viewTab' => $this->viewTab, 'resourceView' => $this->resourceView])),
        ];
    }
}
