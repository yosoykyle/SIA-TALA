<?php

namespace App\Filament\Resources\PromissoryNotes\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('term_id')
                    ->relationship('term', 'term_name')
                    ->searchable()
                    ->preload(),
                Select::make('enrollment_id')
                    ->relationship('enrollment', 'id')
                    ->searchable(),
                Select::make('ledger_entry_id')
                    ->relationship('ledgerEntry', 'id')
                    ->searchable(),
                TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->minValue(0.01),
                DatePicker::make('due_date')
                    ->required(),
                Select::make('status')
                    ->options([
                        'approved' => 'Approved',
                        'active' => 'Active',
                        'expired' => 'Expired',
                        'settled' => 'Settled',
                        'rejected' => 'Rejected',
                    ])
                    ->required()
                    ->default('approved'),
                Textarea::make('reason')
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }
}
