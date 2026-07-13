<?php

namespace App\Filament\Resources\Terms\Schemas;

use App\Models\AcademicYear;
use App\Models\Term;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TermForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Term Identity')
                ->description('Configure the canonical term record used by enrollment, payment deadlines, scheduling, grades, and reporting.')
                ->schema([
                    TextInput::make('label')
                        ->label('Term Name')
                        ->required()
                        ->maxLength(255),
                    Select::make('academic_year_id')
                        ->label('Academic Year')
                        ->options(fn (): array => AcademicYear::query()
                            ->orderByDesc('starts_on')
                            ->get()
                            ->mapWithKeys(fn (AcademicYear $record): array => [$record->id => $record->displayLabel()])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Select the College academic year umbrella that owns this operational term.'),
                    Select::make('type')
                        ->label('Term Type')
                        ->options(Term::typeOptions())
                        ->required(),
                    Select::make('state')
                        ->options(Term::stateOptions())
                        ->default(Term::StateDraft)
                        ->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Academic Dates')
                ->description('These dates drive phase gates and solver scheduling windows.')
                ->schema([
                    DatePicker::make('starts_on')->required(),
                    DatePicker::make('ends_on')->required()->after('starts_on'),
                    TextInput::make('scheduling_slot_minutes')
                        ->label('Scheduling Slot Minutes')
                        ->integer()
                        ->minValue(15)
                        ->maxValue(120)
                        ->default(30)
                        ->required(),
                    CheckboxList::make('scheduling_days')
                        ->label('Scheduling Days')
                        ->options([
                            1 => 'Monday',
                            2 => 'Tuesday',
                            3 => 'Wednesday',
                            4 => 'Thursday',
                            5 => 'Friday',
                            6 => 'Saturday',
                            7 => 'Sunday',
                        ])
                        ->default([1, 2, 3, 4, 5, 6])
                        ->columns(4)
                        ->required(),
                    TimePicker::make('scheduling_day_starts_at')
                        ->label('Scheduling Day Starts At')
                        ->seconds(false)
                        ->default('07:00')
                        ->required(),
                    TimePicker::make('scheduling_day_ends_at')
                        ->label('Scheduling Day Ends At')
                        ->seconds(false)
                        ->default('20:00')
                        ->after('scheduling_day_starts_at')
                        ->required(),
                    TextInput::make('default_max_units')
                        ->label('Default Faculty Max Units')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.25)
                        ->helperText('Optional term default used by faculty load checks. Approved term-specific overrides remain separate records.'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
