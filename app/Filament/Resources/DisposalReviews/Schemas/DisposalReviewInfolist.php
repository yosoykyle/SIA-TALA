<?php

namespace App\Filament\Resources\DisposalReviews\Schemas;

use App\Models\StudentProfile;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DisposalReviewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Disposal Candidate')
                ->schema([
                    TextEntry::make('student_number')->label('Student Number'),
                    TextEntry::make('full_name')
                        ->label('Student Name')
                        ->state(fn (StudentProfile $record): string => collect([$record->first_name, $record->middle_name, $record->last_name])->filter()->implode(' ')),
                    TextEntry::make('archived_at')->label('Archived At')->dateTime(),
                    TextEntry::make('lifecycle_status')->badge(),
                ])
                ->columns(2),
        ]);
    }
}
