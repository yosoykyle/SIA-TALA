<?php

namespace App\Filament\Resources\AcademicYears\Schemas;

use App\Models\AcademicYear;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class AcademicYearForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Academic Year')
                ->description('Maintain the parent College calendar record. Terms under this record hold enrollment, billing, scheduling, and grading gates.')
                ->schema([
                    TextInput::make('label')
                        ->label('Academic Year')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Use the school year label, for example 2026-2027.')
                        ->rule(fn (?AcademicYear $record) => Rule::unique((new AcademicYear)->getTable(), 'label')
                            ->ignore($record?->id))
                        ->rules([
                            fn (?AcademicYear $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                if (blank($value)) {
                                    return;
                                }
                                if (($error = AcademicYear::duplicateLabelError((string) $value, $record?->id)) !== null) {
                                    $fail($error);
                                }
                            },
                        ]),
                    DatePicker::make('starts_on')
                        ->label('School Year Start')
                        ->required(),
                    DatePicker::make('ends_on')
                        ->label('School Year End')
                        ->required()
                        ->rule('after:starts_on')
                        ->rules([
                            fn (Get $get, ?AcademicYear $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                $startsOn = $get('starts_on');
                                if (blank($startsOn) || blank($value)) {
                                    return;
                                }
                                if (($error = AcademicYear::overlappingDateError((string) $startsOn, (string) $value, $record?->id)) !== null) {
                                    $fail($error);
                                }
                                if ($record === null) {
                                    $isOperational = (bool) ($get('is_operational') ?? true);
                                    if (($pastError = AcademicYear::pastOperationalEndDateError((string) $value, $isOperational)) !== null) {
                                        $fail($pastError);
                                    }
                                }
                            },
                        ]),
                    Select::make('state')
                        ->options(AcademicYear::statusOptions())
                        ->default(AcademicYear::StateDraft)
                        ->required(),
                    Toggle::make('is_operational')
                        ->label('Show in operational Term Planning')
                        ->helperText('Turn off for historical intake-only batches used strictly to map continuing curricula.')
                        ->default(true)
                        ->live(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
