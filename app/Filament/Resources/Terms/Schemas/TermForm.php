<?php

namespace App\Filament\Resources\Terms\Schemas;

use App\Models\AcademicYear;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class TermForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Section::make('Protected Term identity')->visible(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                    ->contained(false)
                    ->description('Calendar activation protects the identity and dates. Record calendar successors in Term Planning.')
                    ->schema([
                        TextEntry::make('label')->label('Term'),
                        TextEntry::make('academic_year')->label('Academic year')->state(fn (Term $record): string => $record->academicYear?->displayLabel() ?? 'Not recorded'),
                        TextEntry::make('type')->formatStateUsing(fn (string $state): string => Term::typeOptions()[$state] ?? $state),
                        TextEntry::make('state')->formatStateUsing(fn (string $state): string => Term::stateOptions()[$state] ?? $state),
                        TextEntry::make('starts_on')->date(), TextEntry::make('ends_on')->date(),
                    ])->columns(2)->columnSpanFull(),
                Section::make('Term identity')->hidden(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                    ->contained(false)
                    ->description(fn (?Term $record): string => ($record?->hasActivatedCalendar() ?? false)
                        ? 'This Term has an activated calendar. Its identity and dates are protected; use Term Planning for the approved calendar and teaching setup.'
                        : 'Save the Term name, academic year and type first. New Terms start in Draft; prepare and activate their approved calendar in Term Planning.')
                    ->schema([
                        TextInput::make('label')
                            ->label('Term name')
                            ->required()
                            ->maxLength(255)
                            ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                            ->validatedWhenNotDehydrated(false),
                        Select::make('academic_year_id')
                            ->label('Academic year')
                            ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                            ->validatedWhenNotDehydrated(false)
                            ->options(fn (): array => AcademicYear::query()
                                ->orderByDesc('starts_on')
                                ->get()
                                ->mapWithKeys(fn (AcademicYear $record): array => [$record->id => $record->displayLabel()])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => Term::query()->find(request()->integer('termId'))?->academic_year_id)
                            ->createOptionAction(fn (Action $action): Action => $action->label('Add academic year')->modalHeading('Add academic year')->modalSubmitActionLabel('Create academic year'))
                            ->createOptionForm([
                                TextInput::make('label')->label('Academic year')->required()->maxLength(255)->unique('academic_years', 'label'),
                                DatePicker::make('starts_on')->label('School year start')->required(),
                                DatePicker::make('ends_on')->label('School year end')->required()->after('starts_on'),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                Gate::authorize('create', AcademicYear::class);

                                return (int) AcademicYear::query()->create([...$data, 'state' => AcademicYear::StateDraft])->id;
                            })
                            ->required()
                            ->helperText('Choose the school year that contains this Term.'),
                        Select::make('type')
                            ->label('Type')
                            ->options(Term::typeOptions())
                            ->required()
                            ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                            ->validatedWhenNotDehydrated(false),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Term dates')->hidden(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                    ->contained(false)
                    ->description('These dates identify the Term within its academic year. Instruction dates, teaching days and the 30-minute timetable grid are set in the calendar package in Term Planning.')
                    ->schema([
                        DatePicker::make('starts_on')
                            ->label('Start date')
                            ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                            ->validatedWhenNotDehydrated(false)
                            ->required()
                            ->rules([self::academicYearBoundRule('start')]),
                        DatePicker::make('ends_on')
                            ->label('End date')
                            ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                            ->validatedWhenNotDehydrated(false)
                            ->required()
                            ->after('starts_on')
                            ->rules([self::academicYearBoundRule('end')]),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Faculty teaching-load default')
                    ->contained(false)
                    ->description('Recorded maximum teaching units for Faculty in this Term. An active Faculty load exception takes precedence; generation needs a default for eligible Faculty without an exception.')

                    ->schema([
                        TextInput::make('default_max_units')
                            ->label('Maximum teaching units per Faculty member')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.25)
                            ->helperText('Leave blank when no Term default is recorded.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ])->columns(2)->divided()->columnSpanFull(),
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
