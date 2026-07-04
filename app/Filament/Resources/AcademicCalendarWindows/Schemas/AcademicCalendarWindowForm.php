<?php

namespace App\Filament\Resources\AcademicCalendarWindows\Schemas;

use App\Models\CalendarEvent;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AcademicCalendarWindowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Academic Calendar Window')
                ->description('Official term process date windows. These use absolute start/end dates and do not become recurring weekly scheduling blocks.')
                ->schema([
                    Select::make('term_id')
                        ->relationship('term', 'label')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('process_key')
                        ->label('Process')
                        ->options(CalendarEvent::academicCalendarWindowProcessOptions())
                        ->searchable()
                        ->native(false)
                        ->required(),
                    DateTimePicker::make('start_at')
                        ->label('Opens At')
                        ->seconds(false)
                        ->required(),
                    DateTimePicker::make('end_at')
                        ->label('Closes At')
                        ->seconds(false)
                        ->after('start_at')
                        ->required(),
                    Select::make('state')
                        ->options(CalendarEvent::stateOptions())
                        ->default(CalendarEvent::StateActive)
                        ->required(),
                    TextInput::make('authority')
                        ->label('Authority / Reference')
                        ->maxLength(255)
                        ->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
