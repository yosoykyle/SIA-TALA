<?php

namespace App\Filament\Resources\PromissoryNotes\Schemas;

use App\Models\PromissoryNote;
use App\Models\StudentProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PromissoryNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('student_profile_id')
                    ->label('Student')
                    ->relationship('studentProfile', 'student_id')
                    ->getOptionLabelFromRecordUsing(fn (StudentProfile $record): string => PromissoryNote::studentOptionLabel($record))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('term_id', null);
                        $set('enrollment_id', null);
                        $set('ledger_entry_id', null);
                    })
                    ->required(),
                Select::make('term_id')
                    ->relationship('term', 'term_name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('enrollment_id', null);
                        $set('ledger_entry_id', null);
                    })
                    ->required(),
                Select::make('enrollment_id')
                    ->label('Enrollment')
                    ->options(fn (Get $get): array => PromissoryNote::enrollmentOptionsFor(
                        $get('student_profile_id'),
                        $get('term_id'),
                    ))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->disabled(fn (Get $get): bool => blank($get('student_profile_id')))
                    ->helperText('Choose only from enrollments belonging to the selected student and term.')
                    ->afterStateUpdated(function (Set $set): void {
                        $set('ledger_entry_id', null);
                    })
                    ->required(),
                Select::make('ledger_entry_id')
                    ->label('Ledger Entry')
                    ->options(fn (Get $get): array => PromissoryNote::ledgerEntryOptionsFor(
                        $get('student_profile_id'),
                        $get('term_id'),
                        $get('enrollment_id'),
                    ))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get): bool => blank($get('student_profile_id')))
                    ->helperText('Choose only from ledger entries belonging to the selected student, term, and enrollment.'),
                TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->minValue(0.01),
                DatePicker::make('due_date')
                    ->minDate(now())
                    ->required(),
                Textarea::make('reason')
                    ->required()
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }
}
