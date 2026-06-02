<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ScheduleGenerationRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('term_id')
                    ->required()
                    ->numeric(),
                TextInput::make('status')
                    ->required()
                    ->default('generated'),
                TextInput::make('requested_by')
                    ->required()
                    ->numeric(),
                DateTimePicker::make('generated_at')
                    ->required(),
                TextInput::make('committed_by')
                    ->numeric(),
                DateTimePicker::make('committed_at'),
                TextInput::make('constraint_summary'),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
