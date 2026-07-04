<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\Course;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Course Identity')
                ->description('Maintain the subject code identity. Academic details are versioned in Course Specification Revisions.')
                ->schema([
                    TextInput::make('code')
                        ->label('Subject Code')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),
                    Select::make('state')
                        ->options(Course::stateOptions())
                        ->default(Course::StateActive)
                        ->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
