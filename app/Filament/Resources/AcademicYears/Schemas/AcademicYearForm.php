<?php

namespace App\Filament\Resources\AcademicYears\Schemas;

use App\Models\AcademicYear;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
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
                            ->ignore($record?->id)),
                    DatePicker::make('starts_on')
                        ->label('School Year Start')
                        ->required(),
                    DatePicker::make('ends_on')
                        ->label('School Year End')
                        ->required()
                        ->rule('after:starts_on'),
                    Select::make('state')
                        ->options(AcademicYear::statusOptions())
                        ->default(AcademicYear::StateDraft)
                        ->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
