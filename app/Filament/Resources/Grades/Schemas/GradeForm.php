<?php

namespace App\Filament\Resources\Grades\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class GradeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('enrollment_id')
                    ->relationship('enrollment', 'id')
                    ->required(),
                Select::make('enrollment_subject_id')
                    ->relationship('enrollmentSubject', 'id'),
                Select::make('subject_id')
                    ->relationship('subject', 'id')
                    ->required(),
                Select::make('term_id')
                    ->relationship('term', 'id')
                    ->required(),
                Select::make('faculty_id')
                    ->relationship('faculty', 'name'),
                TextInput::make('prelim_grade')
                    ->numeric(),
                TextInput::make('midterm_grade')
                    ->numeric(),
                TextInput::make('final_grade')
                    ->numeric(),
                TextInput::make('grade')
                    ->numeric(),
                TextInput::make('remarks'),
                Toggle::make('is_inc')
                    ->required(),
                DateTimePicker::make('inc_expires_at'),
                Toggle::make('is_finalized')
                    ->required(),
                TextInput::make('finalized_by')
                    ->numeric(),
                DateTimePicker::make('finalized_at'),
                TextInput::make('reopened_by')
                    ->numeric(),
                DateTimePicker::make('reopened_at'),
            ]);
    }
}
