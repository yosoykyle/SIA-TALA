<?php

namespace App\Filament\Resources\DocumentUploads\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DocumentUploadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('student_profile_id')
                    ->numeric(),
                TextInput::make('user_id')
                    ->numeric(),
                TextInput::make('term_id')
                    ->numeric(),
                TextInput::make('document_type')
                    ->required(),
                TextInput::make('file_disk')
                    ->required()
                    ->default('local'),
                TextInput::make('file_path')
                    ->required(),
                TextInput::make('file_name')
                    ->required(),
                TextInput::make('mime_type'),
                TextInput::make('file_size')
                    ->numeric(),
                TextInput::make('checksum'),
                TextInput::make('upload_status')
                    ->required()
                    ->default('uploaded'),
                TextInput::make('ocr_review_status')
                    ->required()
                    ->default('uploaded'),
                TextInput::make('ocr_confidence')
                    ->numeric(),
                Textarea::make('ocr_text')
                    ->columnSpanFull(),
                DateTimePicker::make('ocr_processed_at'),
                TextInput::make('parser_version'),
                TextInput::make('registrar_reviewed_by')
                    ->numeric(),
                DateTimePicker::make('registrar_reviewed_at'),
                TextInput::make('student_confirmed_payload'),
                DateTimePicker::make('student_confirmed_at'),
                TextInput::make('registrar_approved_payload'),
            ]);
    }
}
