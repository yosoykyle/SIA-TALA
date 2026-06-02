<?php

namespace App\Filament\Resources\GradeCorrections\Schemas;

use App\Enums\GradeCorrectionStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GradeCorrectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('user_id')
                    ->required()
                    ->numeric(),
                Select::make('grade_id')
                    ->relationship('grade', 'id'),
                Select::make('subject_id')
                    ->relationship('subject', 'id')
                    ->required(),
                Select::make('term_id')
                    ->relationship('term', 'id')
                    ->required(),
                TextInput::make('assessment_component'),
                TextInput::make('current_grade')
                    ->numeric(),
                TextInput::make('requested_action')
                    ->required(),
                TextInput::make('reason')
                    ->required(),
                TextInput::make('attachment_paths'),
                Select::make('status')
                    ->options(GradeCorrectionStatus::class)
                    ->default('submitted')
                    ->required(),
                TextInput::make('assigned_to')
                    ->numeric(),
                Select::make('creator_id')
                    ->relationship('creator', 'name'),
                DateTimePicker::make('resolved_at'),
            ]);
    }
}
