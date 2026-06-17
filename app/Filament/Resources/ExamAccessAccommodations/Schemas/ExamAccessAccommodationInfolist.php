<?php

namespace App\Filament\Resources\ExamAccessAccommodations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ExamAccessAccommodationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('studentProfile.student_id')
                    ->label('Student ID'),
                TextEntry::make('studentProfile.user.name')
                    ->label('Student')
                    ->placeholder('-'),
                TextEntry::make('academicYear.academic_year')
                    ->label('Academic Year')
                    ->placeholder('-'),
                TextEntry::make('term.term_name')
                    ->label('Term')
                    ->placeholder('-'),
                TextEntry::make('scope')
                    ->badge(),
                TextEntry::make('basis')
                    ->badge(),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('request_reason')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('certifying_office')
                    ->placeholder('-'),
                TextEntry::make('certification_reference')
                    ->placeholder('-'),
                TextEntry::make('certified_at')
                    ->date()
                    ->placeholder('-'),
                TextEntry::make('evidence_file_name')
                    ->label('Evidence File')
                    ->placeholder('-'),
                TextEntry::make('valid_from')
                    ->date(),
                TextEntry::make('valid_until')
                    ->date(),
                TextEntry::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-'),
                TextEntry::make('reviewer.name')
                    ->label('Reviewed By')
                    ->placeholder('-'),
                TextEntry::make('reviewed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('review_reason')
                    ->placeholder('-')
                    ->columnSpanFull(),
            ]);
    }
}
