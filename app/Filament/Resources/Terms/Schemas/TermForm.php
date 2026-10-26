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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

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
                        ->required(),
                    Select::make('type')
                        ->label('Term')
                        ->options(Term::typeOptions())
                        ->required()
                        ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                        ->validatedWhenNotDehydrated(false),
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
            Section::make(fn (?Term $record): string => ($record?->hasActivatedCalendar() ?? false) ? 'Faculty teaching-load default' : 'Optional settings')
                ->collapsible(fn (?Term $record): bool => ! ($record?->hasActivatedCalendar() ?? false))
                ->collapsed(fn (?Term $record): bool => $record === null)
                ->description(fn (?Term $record): string => ($record?->hasActivatedCalendar() ?? false)
                    ? 'Recorded maximum teaching units for Faculty in this Term. An active Faculty load exception takes precedence; generation needs a default for eligible Faculty without an exception.'
                    : 'Custom name and Faculty teaching-load default. You can set these later.')

                ->schema([
                    TextInput::make('label')
                        ->label('Custom Term name (optional)')
                        ->helperText('Leave blank to use the selected Term.')
                        ->maxLength(255)
                        ->hidden(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                        ->disabled(fn (?Term $record): bool => $record?->hasActivatedCalendar() ?? false)
                        ->validatedWhenNotDehydrated(false),
                    TextInput::make('default_max_units')
                        ->label('Maximum teaching units per Faculty member')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('units')
                        ->step(0.25)
                        ->helperText('Used when a Faculty member has no active load exception. Leave blank when no Term default is recorded.'),
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
