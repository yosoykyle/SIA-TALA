<?php

namespace App\Filament\Resources\LedgerEntries\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LedgerEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('student_profile_id')
                    ->relationship('studentProfile', 'id')
                    ->required(),
                Select::make('term_id')
                    ->relationship('term', 'id'),
                Select::make('enrollment_id')
                    ->relationship('enrollment', 'id'),
                TextInput::make('entry_type')
                    ->required(),
                TextInput::make('reference_type'),
                TextInput::make('reference_id')
                    ->numeric(),
                TextInput::make('description'),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('running_balance')
                    ->numeric(),
                DateTimePicker::make('posted_at'),
                TextInput::make('posted_by')
                    ->numeric(),
            ]);
    }
}
