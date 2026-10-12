<?php

namespace App\Filament\Resources\Terms\Pages;

use App\Filament\Resources\Terms\TermResource;
use App\Support\ReturnsToTermPlanning;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTerm extends ViewRecord
{
    use ReturnsToTermPlanning;

    protected static string $resource = TermResource::class;

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-term-record-page'];

    public function getTitle(): string
    {
        return $this->record->label;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label(fn (): string => $this->record->hasActivatedCalendar() ? 'Faculty teaching-load default' : 'Edit Term identity')->url(fn (): string => TermResource::getUrl('edit', ['record' => $this->record, 'viewTab' => $this->viewTab, 'resourceView' => $this->resourceView])),
            Action::make('returnToPlanning')->label('Return to Term Planning')
                ->color('gray')->url(fn (): string => $this->termPlanningReturnUrl($this->record->id)),
        ];
    }
}
