<?php

namespace App\Filament\Resources\DocumentUploads\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DocumentUploadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('student_profile_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('user_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('term_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('document_type'),
                TextEntry::make('file_disk'),
                TextEntry::make('file_path'),
                TextEntry::make('file_name'),
                TextEntry::make('mime_type')
                    ->placeholder('-'),
                TextEntry::make('file_size')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('checksum')
                    ->placeholder('-'),
                TextEntry::make('upload_status'),
                TextEntry::make('ocr_review_status'),
                TextEntry::make('ocr_confidence')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('ocr_text')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('ocr_processed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('parser_version')
                    ->placeholder('-'),
                TextEntry::make('registrar_reviewed_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('registrar_reviewed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('student_confirmed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
