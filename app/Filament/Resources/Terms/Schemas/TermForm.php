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
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Unique;

class TermForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.terms.edit-context')
                ->visible(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                ->viewData(fn (?Term $record): array => ['term' => $record?->loadMissing('academicYear')])
                ->columnSpanFull(),
            Section::make('Term details')->hidden(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                ->description('Choose the Term and its dates. New Terms start in Draft.')
                ->schema([
                    Select::make('academic_year_id')
                        ->label('Academic year')
                        ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                        ->validatedWhenNotDehydrated(false)
                        ->options(fn (?Term $record): array => AcademicYear::query()
                            ->when($record === null, fn ($query) => $query->where('state', '!=', AcademicYear::StateClosed))
                            ->orderByDesc('starts_on')
                            ->get()
                            ->mapWithKeys(fn (AcademicYear $academicYear): array => [$academicYear->id => $academicYear->displayLabel()])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->default(fn (): ?int => Term::query()->find(request()->integer('termId'))?->academic_year_id)
                        ->createOptionAction(fn (Action $action): Action => $action->label('Add academic year')->modalHeading('Add academic year')->modalSubmitActionLabel('Create academic year'))
                        ->createOptionForm([
                            TextInput::make('label')->label('Academic year')->required()->maxLength(255)->unique('academic_years', 'label', ignoreRecord: false),
                            Grid::make(['default' => 1, 'sm' => 2])
                                ->schema([
                                    DatePicker::make('starts_on')
                                        ->label('Academic year start')
                                        ->native(false)
                                        ->displayFormat('M j, Y')
                                        ->suffixIcon(Heroicon::OutlinedCalendar)
                                        ->required(),
                                    DatePicker::make('ends_on')
                                        ->label('Academic year end')
                                        ->native(false)
                                        ->displayFormat('M j, Y')
                                        ->suffixIcon(Heroicon::OutlinedCalendar)
                                        ->required()
                                        ->after('starts_on'),
                                ]),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            Gate::authorize('create', AcademicYear::class);

                            return (int) AcademicYear::query()->create([...$data, 'state' => AcademicYear::StateDraft])->id;
                        })
                        ->required(),
                    Select::make('type')
                        ->label('Term')
                        ->options(Term::typeOptions())
                        ->required()
                        ->unique(
                            table: Term::class,
                            column: 'type',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('academic_year_id', $get('academic_year_id')),
                        )
                        ->validationMessages([
                            'unique' => 'This academic year already has that Term. Open the existing Term or choose a different one.',
                        ])
                        ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                        ->validatedWhenNotDehydrated(false),
                    Grid::make(['default' => 1, 'sm' => 2])
                        ->columnSpanFull()
                        ->schema([
                            DatePicker::make('starts_on')
                                ->label('Start date')
                                ->native(false)
                                ->displayFormat('M j, Y')
                                ->suffixIcon(Heroicon::OutlinedCalendar)
                                ->minDate(fn (Get $get): ?string => self::academicYearDateBound($get('academic_year_id'), 'start'))
                                ->maxDate(fn (Get $get): ?string => self::academicYearDateBound($get('academic_year_id'), 'end'))
                                ->helperText(fn (Get $get): ?string => self::academicYearBoundsHelperText($get('academic_year_id')))
                                ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                                ->validatedWhenNotDehydrated(false)
                                ->required()
                                ->rules([self::academicYearBoundRule('start')]),
                            DatePicker::make('ends_on')
                                ->label('End date')
                                ->native(false)
                                ->displayFormat('M j, Y')
                                ->suffixIcon(Heroicon::OutlinedCalendar)
                                ->minDate(fn (Get $get): ?string => self::academicYearDateBound($get('academic_year_id'), 'start'))
                                ->maxDate(fn (Get $get): ?string => self::academicYearDateBound($get('academic_year_id'), 'end'))
                                ->helperText('Must be on or after the term start date.')
                                ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                                ->validatedWhenNotDehydrated(false)
                                ->required()
                                ->after('starts_on')
                                ->rules([self::academicYearBoundRule('end')]),
                        ]),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Faculty teaching-load default')
                ->visible(fn (?Term $record): bool => $record !== null)
                ->description('Recorded maximum teaching units for faculty in this Term (24 units by default per CHED CMO 40 s. 2008). An active faculty load exception takes precedence.')
                ->schema([
                    TextInput::make('default_max_units')
                        ->label('Maximum teaching units per faculty member')
                        ->numeric()
                        ->default(24)
                        ->minValue(0)
                        ->suffix('units')
                        ->step(0.25)
                        ->helperText('Defaults to 24 units. Used when a faculty member has no active load exception.'),
                ])
                ->columns(1)
                ->columnSpanFull(),
        ]);
    }

    private static function academicYearDateBound(mixed $academicYearId, string $boundary): ?string
    {
        if (blank($academicYearId)) {
            return null;
        }

        $academicYear = AcademicYear::query()->find($academicYearId);
        if (! $academicYear instanceof AcademicYear) {
            return null;
        }

        return $boundary === 'start'
            ? $academicYear->starts_on?->toDateString()
            : $academicYear->ends_on?->toDateString();
    }

    private static function academicYearBoundsHelperText(mixed $academicYearId): ?string
    {
        if (blank($academicYearId)) {
            return 'Select an academic year first to constrain valid term dates.';
        }

        $academicYear = AcademicYear::query()->find($academicYearId);
        if (! $academicYear instanceof AcademicYear || ! $academicYear->starts_on || ! $academicYear->ends_on) {
            return null;
        }

        return sprintf(
            'Within AY %s (%s – %s).',
            $academicYear->displayLabel(),
            $academicYear->starts_on->format('M j, Y'),
            $academicYear->ends_on->format('M j, Y'),
        );
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
