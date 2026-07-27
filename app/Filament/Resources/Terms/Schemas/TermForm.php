<?php

namespace App\Filament\Resources\Terms\Schemas;

use App\Models\AcademicYear;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                    DatePicker::make('starts_on')
                        ->required()
                        ->rules([self::academicYearBoundRule('start')]),
                    DatePicker::make('ends_on')
                        ->required()
                        ->after('starts_on')
                        ->rules([self::academicYearBoundRule('end')]),
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
                        ->timezone((string) config('app.timezone'))
                        ->seconds(false)
                        ->default('07:00')
                        ->required(),
                    TimePicker::make('scheduling_day_ends_at')
                        ->label('Scheduling Day Ends At')
                        ->timezone((string) config('app.timezone'))
                        ->seconds(false)
                        ->default('21:00')
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

    private static function academicYearBoundRule(string $boundary): Closure
    {
        return function (Get $get) use ($boundary): Closure {
            return function (string $attribute, mixed $value, Closure $fail) use ($get, $boundary): void {
                $academicYearId = $get('academic_year_id');

                if (blank($academicYearId) || blank($value)) {
                    return;
                }

                $academicYear = AcademicYear::query()->find($academicYearId);

                if (! $academicYear instanceof AcademicYear) {
                    return;
                }

                $date = CarbonImmutable::parse($value)->startOfDay();
                $startsOn = CarbonImmutable::parse($academicYear->starts_on)->startOfDay();
                $endsOn = CarbonImmutable::parse($academicYear->ends_on)->startOfDay();

                if ($date->betweenIncluded($startsOn, $endsOn)) {
                    return;
                }

                $label = $boundary === 'start' ? 'start' : 'end';
                $fail("The term {$label} date must be within {$academicYear->displayLabel()}.");
            };
        };
    }
}
