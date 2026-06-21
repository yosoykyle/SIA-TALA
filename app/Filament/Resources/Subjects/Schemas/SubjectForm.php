<?php

namespace App\Filament\Resources\Subjects\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Subject Details')
                ->description('Maintain the canonical subject catalog used by curricula, faculty eligibility, grades, and scheduling demand.')
                ->schema([
                    TextInput::make('code')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                    TextInput::make('description')
                        ->label('Subject Title')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('units')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(99.99)
                        ->default('3.00'),
                    TextInput::make('lec_hours')
                        ->label('Legacy Lecture Hours')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(99.99)
                        ->default('3.00'),
                    Hidden::make('department')
                        ->default('college')
                        ->dehydrated(),
                    Select::make('subject_type')
                        ->label('Subject Type')
                        ->options([
                            'major' => 'Major / Professional',
                            'general_education' => 'General Education',
                            'professional_tesda' => 'Professional / TESDA',
                        ])
                        ->searchable(),
                    Select::make('category')
                        ->options([
                            'lecture' => 'Lecture',
                            'laboratory' => 'Laboratory',
                            'seminar' => 'Seminar',
                            'practicum' => 'Practicum',
                        ])
                        ->searchable(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
