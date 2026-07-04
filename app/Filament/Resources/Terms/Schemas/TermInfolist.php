<?php

namespace App\Filament\Resources\Terms\Schemas;

use App\Models\Term;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TermInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('label')->label('Term Name'),
            TextEntry::make('academic_year_label')
                ->label('Academic Year')
                ->state(fn (Term $record): string => $record->academicYear?->displayLabel() ?? '-'),
            TextEntry::make('type')
                ->label('Term Type')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => Term::typeOptions()[$state] ?? str((string) $state)->headline()->toString()),
            TextEntry::make('state')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => Term::stateOptions()[$state] ?? str((string) $state)->headline()->toString()),
            TextEntry::make('starts_on')->date(),
            TextEntry::make('ends_on')->date(),
            TextEntry::make('scheduling_slot_minutes')->suffix(' minutes'),
            TextEntry::make('default_max_units')->placeholder('-'),
        ]);
    }
}
