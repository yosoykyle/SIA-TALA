<?php

namespace App\Filament\Resources\EnrollmentSubjects\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class EnrollmentSubjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('enrollment.studentProfile.student_id')
                    ->label('Student ID'),
                TextEntry::make('enrollment.studentProfile.user.name')
                    ->label('Student'),
                TextEntry::make('enrollment.studentProfile.operational_status')
                    ->label('Advising Status')
                    ->badge(),
                TextEntry::make('enrollment.status')
                    ->label('Enrollment Status')
                    ->badge(),
                TextEntry::make('enrollment.section.name')
                    ->label('Section')
                    ->placeholder('-'),
                TextEntry::make('subject.code')
                    ->label('Subject'),
                TextEntry::make('subject.description')
                    ->label('Description'),
                TextEntry::make('enrollment.term.term_name')
                    ->label('Term'),
                TextEntry::make('units')
                    ->numeric(),
                TextEntry::make('lec_hours')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('status')
                    ->badge(),
                IconEntry::make('is_dropped')
                    ->label('Dropped')
                    ->boolean(),
                TextEntry::make('grade.prelim_grade')
                    ->label('Prelim/Q1')
                    ->placeholder('-'),
                TextEntry::make('grade.midterm_grade')
                    ->label('Midterm/Q2')
                    ->placeholder('-'),
                TextEntry::make('grade.final_grade')
                    ->label('Final Raw')
                    ->placeholder('-'),
                TextEntry::make('grade.grade')
                    ->label('Final Grade')
                    ->placeholder('-'),
                TextEntry::make('grade.remarks')
                    ->label('Remarks')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('grade.finalized_at')
                    ->label('Finalized At')
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
