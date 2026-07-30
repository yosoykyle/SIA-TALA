<?php

namespace App\Filament\Resources\GraduationReviewBatches\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GraduationReviewBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Completion eligibility review')
                ->description('Create one Registrar review list for an academic year and term. New reviews remain open until explicitly closed.')
                ->schema([
                    Select::make('academic_year_id')
                        ->relationship('academicYear', 'label')
                        ->required()
                        ->searchable()
                        ->preload(),
                    Select::make('term_id')
                        ->relationship('term', 'label')
                        ->required()
                        ->searchable()
                        ->preload(),
                    TextInput::make('name')
                        ->helperText('Use a name staff can recognize in the review queue.')
                        ->required()
                        ->maxLength(255),
                ])
                ->columns(2),
        ]);
    }
}
