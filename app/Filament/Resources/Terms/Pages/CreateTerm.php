<?php

namespace App\Filament\Resources\Terms\Pages;

use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\Terms\TermResource;
use App\Models\Term;
use App\Support\ReturnsToTermPlanning;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Url;

class CreateTerm extends CreateRecord
{
    use ReturnsToTermPlanning;

    protected static string $resource = TermResource::class;

    /** @var array<string, string> */
    protected array $extraBodyAttributes = ['class' => 'tala-term-record-page'];

    #[Url]
    public ?int $termId = null;

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')->label('Cancel and return to Term Planning')->color('gray')
            ->url($this->termPlanningReturnUrl($this->termId));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'state' => Term::StateDraft, 'scheduling_slot_minutes' => 30, 'scheduling_days' => null];
    }

    protected function getRedirectUrl(): string
    {
        return TermPlanningWorkbench::getUrl(['termId' => $this->record->id, 'viewTab' => 'plan']);
    }
}
