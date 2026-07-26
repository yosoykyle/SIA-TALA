<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use App\Actions\Enrollment\EnrollmentGateReviewSummary;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Support\DisplayDateTime;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class EnrollmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Current Enrollment Status')
                    ->description('Review the current state, the next action, and the office responsible before changing placement.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('status')
                                    ->label('Current Status')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => filled($state)
                                        ? Str::headline($state)
                                        : 'Unknown'),
                                TextEntry::make('term.label')
                                    ->label('Term'),
                                TextEntry::make('enrollment_next_step')
                                    ->label('Next Step')
                                    ->state(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->nextStep($record))
                                    ->columnSpanFull(),
                                TextEntry::make('enrollment_responsible_office')
                                    ->label('Responsible Office')
                                    ->state(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->responsibleOffice($record))
                                    ->badge(),
                                TextEntry::make('student_type')
                                    ->label('Enrollment Type')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => filled($state)
                                        ? Str::headline($state)
                                        : 'Unknown')
                                    ->placeholder('-'),
                                TextEntry::make('status_reason')
                                    ->label('Reason / Notes')
                                    ->visible(fn (?string $state): bool => filled($state))
                                    ->columnSpanFull(),
                            ]),
                    ]),
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
                            ]),
                    ]),
                Section::make('Enrollment Dates')
                    ->schema([
                        Grid::make(3)
                            ->schema([
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
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Course Selections and Placements')
                    ->description('A proposed section does not reserve a seat. A confirmed section has an active seat reservation and published schedule.')
                    ->schema([
                        RepeatableEntry::make('course_placement_rows')
                            ->label('Courses')
                            ->state(function (Enrollment $record): array {
                                return $record->courseEnrollments()
                                    ->with([
                                        'termOffering.curriculumEntry.courseSpecification.course',
                                        'proposedSection',
                                        'seatReservations.section',
                                        'scheduleBindings',
                                    ])
                                    ->where('status', 'active')
                                    ->oldest('id')
                                    ->get()
                                    ->map(function (CourseEnrollment $courseEnrollment): array {
                                        $reservation = $courseEnrollment->seatReservations
                                            ->sortByDesc('id')
                                            ->first();

                                        return [
                                            'subject' => collect([
                                                $courseEnrollment->termOffering?->course()?->code,
                                                $courseEnrollment->termOffering?->courseSpecification()?->title,
                                            ])->filter()->implode(' - '),
                                            'proposed_section' => $courseEnrollment->proposedSection?->code,
                                            'confirmed_section' => $reservation instanceof EnrollmentSeatReservation
                                                ? $reservation->section?->code
                                                : null,
                                            'reservation_status' => $reservation instanceof EnrollmentSeatReservation
                                                ? $reservation->status
                                                : null,
                                            'reservation_deadline' => $reservation instanceof EnrollmentSeatReservation
                                                ? DisplayDateTime::format($reservation->deadline, 'M j, Y g:i A', 'Not set')
                                                : null,
                                            'active_meetings' => $courseEnrollment->scheduleBindings
                                                ->where('is_active', true)
                                                ->count(),
                                        ];
                                    })
                                    ->all();
                            })
                            ->schema([
                                TextEntry::make('subject')
                                    ->label('Subject')
                                    ->weight('bold'),
                                TextEntry::make('proposed_section')
                                    ->label('Student Proposal')
                                    ->placeholder('None'),
                                TextEntry::make('confirmed_section')
                                    ->label('Confirmed Section')
                                    ->placeholder('Not confirmed'),
                                TextEntry::make('reservation_status')
                                    ->label('Seat Reservation')
                                    ->badge()
                                    ->placeholder('No capacity held'),
                                TextEntry::make('reservation_deadline')
                                    ->label('Reservation Deadline')
                                    ->placeholder('-'),
                                TextEntry::make('active_meetings')
                                    ->label('Scheduled Meetings'),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Enrollment Gate Review')
                    ->description('Detailed gate evidence for staff review. A missing result is shown as Not Checked and does not create a database record.')
                    ->schema([
                        TextEntry::make('gate_review_current')
                            ->label('Next Step')
                            ->state(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->nextStep($record))
                            ->badge()
                            ->color(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->compactStatusColor($record)),
                        TextEntry::make('gate_review_office')
                            ->label('Responsible Office')
                            ->state(fn (Enrollment $record): string => app(EnrollmentGateReviewSummary::class)->responsibleOffice($record))
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
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}
