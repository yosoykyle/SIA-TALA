<?php

namespace App\Filament\Resources\EnrollmentSubjects\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class EnrollmentSubjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('enrollment_id')
                    ->required()
                    ->numeric(),
                TextInput::make('subject_id')
                    ->required()
                    ->numeric(),
                TextInput::make('section_meeting_id')
                    ->numeric(),
                TextInput::make('units')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('lec_hours')
                    ->numeric(),
                TextInput::make('status')
                    ->required()
                    ->default('enrolled'),
                Toggle::make('is_dropped')
                    ->required(),
                DateTimePicker::make('dropped_at'),
            ]);
    }
}
