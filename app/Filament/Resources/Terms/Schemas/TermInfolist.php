<?php

namespace App\Filament\Resources\Terms\Schemas;

use App\Models\Term;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TermInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('term_name')->label('Term Name'),
            TextEntry::make('academic_year_label')
                ->label('Academic Year')
                ->state(fn (Term $record): string => $record->academicYear?->displayLabel() ?? '-'),
            TextEntry::make('term_type')->label('Term Type')->badge(),
            IconEntry::make('is_active')->label('Active')->boolean(),
            TextEntry::make('term_start_date')->date(),
            TextEntry::make('term_end_date')->date(),
            TextEntry::make('class_start_date')->date()->placeholder('-'),
            TextEntry::make('class_end_date')->date()->placeholder('-'),
            TextEntry::make('scheduling_starts_at')->dateTime()->placeholder('-'),
            TextEntry::make('enrollment_starts_at')->dateTime()->placeholder('-'),
            TextEntry::make('enrollment_ends_at')->dateTime()->placeholder('-'),
            TextEntry::make('late_enrollment_ends_at')->dateTime()->placeholder('-'),
            TextEntry::make('payment_deadline')->dateTime()->placeholder('-'),
            TextEntry::make('adjustment_ends_at')->dateTime()->placeholder('-'),
            TextEntry::make('locked_at')->dateTime()->placeholder('-'),
        ]);
    }
}
