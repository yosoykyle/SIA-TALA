<?php

namespace App\Filament\Resources\GraduationReviewBatches\Schemas;

use App\Models\GraduationReviewBatch;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GraduationReviewBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Batch Details')
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
                        ->required()
                        ->maxLength(255),
                    Select::make('state')
                        ->options([
                            GraduationReviewBatch::StateOpen => 'Open',
                            GraduationReviewBatch::StateClosed => 'Closed',
                        ])
                        ->default(GraduationReviewBatch::StateOpen)
                        ->required(),
                    KeyValue::make('filter_summary')
                        ->label('Filter Summary')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
