<?php

namespace App\Filament\Resources\Terms\Schemas;

use App\Models\AcademicYear;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TermForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Term Identity')
                ->description('Configure the canonical term record used by enrollment, payment deadlines, scheduling, grades, and document requests.')
                ->schema([
                    TextInput::make('term_name')
                        ->label('Term Name')
                        ->required()
                        ->maxLength(255),
                    Select::make('academic_year_id')
                        ->label('Academic Year')
                        ->relationship('academicYear', 'academic_year')
                        ->getOptionLabelFromRecordUsing(fn (AcademicYear $record): string => $record->displayLabel())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Select the College academic year umbrella that owns this operational term.'),
                    Select::make('term_type')
                        ->label('Term Type')
                        ->options([
                            'quarter' => 'Quarter',
                            'semester' => 'Semester',
                            'summer' => 'Summer',
                        ])
                        ->required(),
                    Toggle::make('is_active')
                        ->label('Active Term')
                        ->default(true),
                ])
                ->columns(2)
                ->columnSpanFull(),
            Section::make('Academic Dates')
                ->description('These dates drive phase gates and solver scheduling windows.')
                ->schema([
                    DatePicker::make('term_start_date')->required(),
                    DatePicker::make('term_end_date')->required(),
                    DatePicker::make('class_start_date'),
                    DatePicker::make('class_end_date'),
                    DateTimePicker::make('scheduling_starts_at')->seconds(false),
                    DateTimePicker::make('enrollment_starts_at')->seconds(false),
                    DateTimePicker::make('enrollment_ends_at')->seconds(false),
                    DateTimePicker::make('late_enrollment_ends_at')->seconds(false),
                    DateTimePicker::make('payment_deadline')->seconds(false),
                    DateTimePicker::make('adjustment_ends_at')->seconds(false),
                    DateTimePicker::make('locked_at')->seconds(false)->disabled()->dehydrated(false),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
