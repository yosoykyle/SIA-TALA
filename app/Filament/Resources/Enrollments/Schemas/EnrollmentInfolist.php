<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use App\Actions\Enrollment\EnrollmentGateReviewSummary;
use App\Models\Enrollment;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EnrollmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Student')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('studentProfile.student_number')
                                    ->label('Student No.'),
                                TextEntry::make('studentProfile.last_name')
                                    ->label('Name')
                                    ->state(fn (Enrollment $record): string => collect([
                                        $record->studentProfile?->last_name,
                                        $record->studentProfile?->first_name,
                                    ])->filter()->implode(', ')),
                                TextEntry::make('studentProfile.program.name')
                                    ->label('Program')
                                    ->placeholder('-'),
                                TextEntry::make('student_type')
                                    ->label('Student Type')
                                    ->badge()
                                    ->placeholder('-'),
                            ]),
                    ]),
                Section::make('Enrollment')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('term.term_name')
                                    ->label('Term'),
                                TextEntry::make('status')
                                    ->badge(),
                                TextEntry::make('registered_at')
                                    ->label('Registered At')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('officially_enrolled_at')
                                    ->label('Officially Enrolled At')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('cancelled_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('dropped_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('withdrawn_at')
                                    ->dateTime()
                                    ->placeholder('-'),
                                TextEntry::make('status_reason')
                                    ->columnSpanFull()
                                    ->placeholder('-'),
                            ]),
                    ]),
                Section::make('Enrollment Gate Review')
                    ->description('Read-only TAL-87B staff summary from recorded gate results; missing gate rows are shown as Not Checked without database writes.')
                    ->schema([
                        TextEntry::make('gate_review_current')
                            ->label('Current blocker / next gate')
                            ->state(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->compactStatus($record))
                            ->badge()
                            ->color(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->compactStatusColor($record)),
                        TextEntry::make('gate_review_office')
                            ->label('Responsible Office')
                            ->state(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->compactResponsibleOffice($record))
                            ->badge(),
                        RepeatableEntry::make('gate_review_rows')
                            ->label('Gate Summary')
                            ->state(fn (Enrollment $record): array => app(EnrollmentGateReviewSummary::class)->rows($record))
                            ->schema([
                                TextEntry::make('label')
                                    ->label('Gate')
                                    ->weight('bold'),
                                TextEntry::make('result_label')
                                    ->label('Result')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'Passed' => 'success',
                                        'Failed' => 'danger',
                                        'Pending Review' => 'warning',
                                        'Waived', 'Overridden' => 'info',
                                        'Not Checked' => 'gray',
                                        'Not Applicable' => 'gray',
                                        default => 'gray',
                                    }),
                                TextEntry::make('office_label')
                                    ->label('Office'),
                                TextEntry::make('blocker_code')
                                    ->label('Blocker Code')
                                    ->placeholder('-'),
                                TextEntry::make('blocker_message')
                                    ->label('Blocker / Message')
                                    ->placeholder('-'),
                                TextEntry::make('source_reference')
                                    ->label('Source')
                                    ->placeholder('-'),
                                TextEntry::make('checked_at')
                                    ->label('Checked At')
                                    ->placeholder('-'),
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
