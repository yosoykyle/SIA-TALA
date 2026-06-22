<?php

namespace App\Filament\Resources\DocumentUploads\Schemas;

use App\Models\DocumentUpload;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentUploadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Applicant / Student and Upload Evidence')
                    ->schema([
                        TextEntry::make('studentProfile.student_id')
                            ->label('Student ID')
                            ->placeholder('-'),
                        TextEntry::make('studentProfile.user.name')
                            ->label('Student')
                            ->placeholder('-'),
                        TextEntry::make('applicantIntake.user.name')
                            ->label('Applicant')
                            ->placeholder('-'),
                        TextEntry::make('user.name')
                            ->label('Uploaded By')
                            ->placeholder('-'),
                        TextEntry::make('term.term_name')
                            ->label('Term')
                            ->placeholder('-'),
                        TextEntry::make('document_type')
                            ->label('Document')
                            ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->headline()->toString()),
                        TextEntry::make('file_name')
                            ->label('Source File')
                            ->placeholder('-'),
                        TextEntry::make('mime_type')
                            ->label('File Type')
                            ->placeholder('-'),
                        TextEntry::make('file_size')
                            ->label('File Size')
                            ->numeric()
                            ->placeholder('-'),
                        TextEntry::make('checksum')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Registrar Review Evidence')
                    ->schema([
                        TextEntry::make('upload_status')
                            ->label('Upload Status')
                            ->badge(),
                        TextEntry::make('review_status')
                            ->label('Review Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => DocumentUpload::reviewStatusOptions()[$state] ?? 'Unknown')
                            ->color(fn (DocumentUpload $record): string => DocumentUpload::reviewStatusColor($record->review_status)),
                    ])
                    ->columns(2),
                Section::make('Review Timeline')
                    ->schema([
                        TextEntry::make('registrarReviewer.name')
                            ->label('Reviewed By')
                            ->placeholder('-'),
                        TextEntry::make('registrar_reviewed_at')
                            ->label('Reviewed At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('student_confirmed_at')
                            ->label('Student Confirmed At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->label('Uploaded At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->label('Last Updated At')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(2),
            ]);
    }
}
