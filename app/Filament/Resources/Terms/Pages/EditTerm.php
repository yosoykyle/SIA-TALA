<?php

namespace App\Filament\Resources\Terms\Pages;

use App\Filament\Resources\Terms\TermResource;
use App\Models\Term;
use App\Support\ReturnsToTermPlanning;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditTerm extends EditRecord
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
        return [...$this->getTermPlanningPageClasses(), 'tala-term-form'];
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

    public function getTitle(): string
    {
        return $this->record->hasActivatedCalendar() ? 'Faculty teaching-load default' : 'Edit Term identity';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! $this->record->hasActivatedCalendar() && blank($data['label'] ?? null)) {
            $data['label'] = Term::typeOptions()[$data['type']];
        }

        return collect($data)->except(['state', 'scheduling_slot_minutes', 'scheduling_days', 'scheduling_day_starts_at', 'scheduling_day_ends_at'])->all();
    }

    protected function getRedirectUrl(): string
    {
        return $this->termPlanningReturnUrl($this->record->id);
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')->label('Cancel and return to Term Planning')->color('gray')
            ->url($this->getRedirectUrl());
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Term {
            $term = Term::query()->whereKey($record)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $term);
            if ($term->hasActivatedCalendar()) {
                foreach (['label', 'academic_year_id', 'type', 'starts_on', 'ends_on'] as $field) {
                    if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $term->getRawOriginal($field)) {
                        throw ValidationException::withMessages(['label' => 'This Term has activated calendar history. Retain its recorded identity and dates; record approved calendar successors in Term Planning.']);
                    }
                }
            }
            $term->update($data);

            return $term;
        }, attempts: 3);
    }
}
