<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ImportBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('import_type')
                    ->options([
                        'student_data' => 'Student data',
                        'legacy_grades' => 'Legacy grades',
                        'legacy_financial' => 'Legacy financial',
                        'enrollment_records' => 'Enrollment records',
                        'curriculum' => 'Curriculum',
                    ])
                    ->required(),
                TextInput::make('file_name')
                    ->required(),
                TextInput::make('file_path')
                    ->required(),
                TextInput::make('total_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('valid_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('error_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('skipped_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                Select::make('status')
                    ->options(['pending_review' => 'Pending review', 'committed' => 'Committed', 'cancelled' => 'Cancelled'])
                    ->default('pending_review')
                    ->required(),
                TextInput::make('imported_by')
                    ->required()
                    ->numeric(),
                TextInput::make('committed_by')
                    ->numeric(),
                DateTimePicker::make('committed_at'),
                TextInput::make('error_log')
                    ->required()
                    ->default(null),
            ]);
    }
}
