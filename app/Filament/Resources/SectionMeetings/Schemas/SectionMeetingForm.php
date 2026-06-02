<?php

namespace App\Filament\Resources\SectionMeetings\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema;

class SectionMeetingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('term_id')
                    ->required()
                    ->numeric(),
                TextInput::make('section_id')
                    ->required()
                    ->numeric(),
                TextInput::make('subject_id')
                    ->required()
                    ->numeric(),
                TextInput::make('faculty_id')
                    ->numeric(),
                TextInput::make('room'),
                TextInput::make('day_of_week')
                    ->numeric(),
                TimePicker::make('starts_at'),
                TimePicker::make('ends_at'),
                TextInput::make('modality')
                    ->required(),
                TextInput::make('schedule_generation_run_id')
                    ->numeric(),
                TextInput::make('committed_by')
                    ->required()
                    ->numeric(),
                DateTimePicker::make('committed_at')
                    ->required(),
            ]);
    }
}
