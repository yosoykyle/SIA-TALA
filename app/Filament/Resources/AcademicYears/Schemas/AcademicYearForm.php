<?php

namespace App\Filament\Resources\AcademicYears\Schemas;

use App\Models\AcademicYear;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                ->description('Maintain the parent calendar record for one education level. Terms under this record hold enrollment, billing, scheduling, and grading gates.')
                ->schema([
                    TextInput::make('academic_year')
                        ->label('Academic Year')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Use the school year label, for example 2026-2027.')
                        ->rule(fn (Get $get, ?AcademicYear $record) => Rule::unique((new AcademicYear)->getTable(), 'academic_year')
                            ->where('education_level', $get('education_level'))
                            ->ignore($record?->id)),
                    Select::make('education_level')
                        ->label('Education Level')
                        ->options(AcademicYear::educationLevelOptions())
                        ->required(),
                    DatePicker::make('school_year_start_date')
                        ->label('School Year Start')
                        ->required(),
                    DatePicker::make('school_year_end_date')
                        ->label('School Year End')
                        ->required()
                        ->rule('after_or_equal:school_year_start_date'),
                    Select::make('status')
                        ->options(AcademicYear::statusOptions())
                        ->default('draft')
                        ->required(),
                    Textarea::make('reference_note')
                        ->label('Reference Note')
                        ->rows(3)
                        ->maxLength(1000)
                        ->helperText('Record DepEd, CHED, or school-approved reference context for this calendar.'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
