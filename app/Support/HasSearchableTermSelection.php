<?php

namespace App\Support;

use App\Models\Term;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

trait HasSearchableTermSelection
{
    public function renderingHasSearchableTermSelection(): void
    {
        \Filament\Support\original_request()->attributes->set('tala.selected_term_id', $this->termId);
    }

    public function termSelection(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('termId')->label('Academic Year and Term')
                ->options(fn (): array => Term::query()->with('academicYear')->latest('starts_on')->get()
                    ->mapWithKeys(fn (Term $term): array => [$term->id => $term->academicYear?->label.' · '.$term->label])->all())
                ->placeholder('Choose a Term')->selectablePlaceholder(false)->searchable()->live()
                ->afterStateUpdated(fn (mixed $state) => $this->selectTerm((int) $state)),
        ]);
    }
}
