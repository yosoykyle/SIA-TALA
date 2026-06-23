<?php

namespace App\Filament\Resources\GradeSubmissionPackages\Schemas;

use App\Models\GradeSubmissionPackage;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GradeSubmissionPackageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Package')
                    ->schema([
                        TextEntry::make('term.term_name')
                            ->label('Term'),
                        TextEntry::make('section.name')
                            ->label('Section'),
                        TextEntry::make('subject.code')
                            ->label('Subject'),
                        TextEntry::make('subject.description')
                            ->label('Subject Description'),
                        TextEntry::make('faculty.name')
                            ->label('Faculty'),
                        TextEntry::make('state')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                            ->color(fn (string $state): string => match ($state) {
                                GradeSubmissionPackage::StateSubmitted => 'warning',
                                GradeSubmissionPackage::StateReturned => 'danger',
                                GradeSubmissionPackage::StateVerifiedFinalized => 'success',
                                default => 'gray',
                            }),
                        TextEntry::make('roster_snapshot_checksum')
                            ->label('Roster Checksum')
                            ->copyable(),
                        TextEntry::make('submittedBy.name')
                            ->label('Submitted By'),
                        TextEntry::make('submitted_at')
                            ->dateTime(),
                        TextEntry::make('registrarReviewer.name')
                            ->label('Registrar Reviewer')
                            ->placeholder('-'),
                        TextEntry::make('registrar_reviewed_at')
                            ->label('Reviewed At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('return_reason')
                            ->label('Return Reason')
                            ->placeholder('-'),
                        TextEntry::make('finalized_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(3),
                Section::make('Grade Rows')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('Submitted Rows')
                            ->schema([
                                TextEntry::make('enrollment.studentProfile.student_id')
                                    ->label('Student ID'),
                                TextEntry::make('enrollment.studentProfile.user.name')
                                    ->label('Student'),
                                TextEntry::make('entered_values.prelim_grade')
                                    ->label('Prelim'),
                                TextEntry::make('entered_values.midterm_grade')
                                    ->label('Midterm'),
                                TextEntry::make('entered_values.final_grade')
                                    ->label('Final Raw'),
                                TextEntry::make('derived_grade.grade')
                                    ->label('Final Grade'),
                                TextEntry::make('derived_grade.remarks')
                                    ->label('Remarks')
                                    ->badge(),
                                TextEntry::make('derived_grade.is_inc')
                                    ->label('INC')
                                    ->formatStateUsing(fn (bool|string|null $state): string => filter_var($state, FILTER_VALIDATE_BOOL) ? 'Yes' : 'No'),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
