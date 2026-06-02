<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EnrollmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Enrollment Context')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('student_profile_id')
                                    ->relationship('studentProfile', 'student_id')
                                    ->label('Student')
                                    ->required()
                                    ->searchable()
                                    ->preload(),
                                Select::make('term_id')
                                    ->relationship('term', 'term_name')
                                    ->label('Term')
                                    ->required()
                                    ->searchable()
                                    ->preload(),
                                Select::make('section_id')
                                    ->relationship('section', 'name')
                                    ->label('Section')
                                    ->searchable()
                                    ->preload(),
                                Select::make('status')
                                    ->required()
                                    ->options([
                                        'pending_payment' => 'Pending Payment',
                                        'pre_enrolled' => 'Pre-Enrolled',
                                        'officially_enrolled' => 'Officially Enrolled',
                                        'ineligible' => 'Ineligible',
                                        'completed' => 'Completed',
                                    ])
                                    ->default('pending_payment'),
                                Select::make('student_type')
                                    ->options([
                                        'new' => 'New/Freshmen',
                                        'transferee' => 'Transferee',
                                        'regular' => 'Regular',
                                        'irregular' => 'Irregular',
                                        'returnee' => 'Returnee',
                                    ]),
                                Select::make('year_level')
                                    ->options([
                                        'Grade 11' => 'Grade 11',
                                        'Grade 12' => 'Grade 12',
                                        '1st Year' => '1st Year',
                                        '2nd Year' => '2nd Year',
                                        '3rd Year' => '3rd Year',
                                        '4th Year' => '4th Year',
                                    ]),
                                Select::make('modality')
                                    ->options([
                                        'on_site' => 'On-site',
                                        'online' => 'Online',
                                        'modular' => 'Modular',
                                    ]),
                                Select::make('lis_status')
                                    ->required()
                                    ->options([
                                        'not_encoded' => 'Not Encoded',
                                        'encoded' => 'Encoded',
                                        'error' => 'Error',
                                    ])
                                    ->default('not_encoded'),
                                Toggle::make('is_late_enrollment')
                                    ->label('Late enrollment')
                                    ->default(false),
                            ]),
                    ]),
                Section::make('Lifecycle Dates')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                DateTimePicker::make('enrolled_at'),
                                DateTimePicker::make('pre_enrolled_at'),
                                DateTimePicker::make('officially_enrolled_at'),
                                DateTimePicker::make('completed_at'),
                            ]),
                    ]),
            ]);
    }
}
