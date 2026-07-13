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
            TextEntry::make('scheduling_days')
                ->formatStateUsing(fn (array $state): string => collect($state)
                    ->map(fn (int|string $day): string => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'][(int) $day])
                    ->implode(', ')),
            TextEntry::make('scheduling_day_starts_at')->time('H:i'),
            TextEntry::make('scheduling_day_ends_at')->time('H:i'),
            TextEntry::make('default_max_units')->placeholder('-'),
        ]);
    }
}
