<?php

namespace App\Filament\Resources\Grades\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class GradeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('enrollment.studentProfile.student_id')
                    ->label('Student ID')
                    ->placeholder('-'),
                TextEntry::make('enrollment.studentProfile.user.name')
                    ->label('Student')
                    ->placeholder('-'),
                TextEntry::make('subject.code')
                    ->label('Subject'),
                TextEntry::make('subject.description')
                    ->label('Subject Description')
                    ->placeholder('-'),
                TextEntry::make('term.term_name')
                    ->label('Term'),
                TextEntry::make('faculty.name')
                    ->label('Faculty')
                    ->placeholder('-'),
                TextEntry::make('prelim_grade')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('midterm_grade')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('final_grade')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('grade')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('remarks')
                    ->placeholder('-'),
                IconEntry::make('is_inc')
                    ->boolean(),
                TextEntry::make('inc_expires_at')
                    ->dateTime()
                    ->placeholder('-'),
                IconEntry::make('is_finalized')
                    ->boolean(),
                TextEntry::make('finalizedBy.name')
                    ->label('Finalized By')
                    ->placeholder('-'),
                TextEntry::make('finalized_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('reopenedBy.name')
                    ->label('Reopened By')
                    ->placeholder('-'),
                TextEntry::make('reopened_at')
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
