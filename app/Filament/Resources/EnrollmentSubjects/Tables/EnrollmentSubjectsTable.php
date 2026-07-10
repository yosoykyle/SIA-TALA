<?php

namespace App\Filament\Resources\EnrollmentSubjects\Tables;

use App\Actions\Faculty\FacultyClassListService;
use App\Models\EnrollmentSubject;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EnrollmentSubjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'enrollment.studentProfile.user',
                'enrollment.studentProfile.program',
                'enrollment.term',
                'enrollment.section',
                'subject',
                'sectionMeeting',
            ]))
            ->columns([
                TextColumn::make('enrollment.studentProfile.student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('enrollment.studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('enrollment.section.name')
                    ->label('Section')
                    ->searchable(),
                TextColumn::make('subject.code')
                    ->label('Subject')
                    ->searchable(),
                TextColumn::make('subject.description')
                    ->label('Description')
                    ->limit(35)
                    ->toggleable(),
                TextColumn::make('enrollment.term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('enrollment.studentProfile.operational_status')
                    ->label('Advising Status')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('enrollment.status')
                    ->label('Enrollment')
                    ->badge(),
                TextColumn::make('finance_status')
                    ->label('Finance')
                    ->badge()
                    ->state(fn (EnrollmentSubject $record): string => self::financeStatus($record))
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                    ->color(fn (string $state): string => $state === 'paid' ? 'success' : 'warning'),
                TextColumn::make('units')
                    ->label('Units')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('lec_hours')
                    ->label('Lec Hours')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    private static function financeStatus(EnrollmentSubject $record): string
    {
        $record->loadMissing('enrollment.studentProfile');

        return app(FacultyClassListService::class)->facultyPaymentStatusFor(
            enrollmentId: $record->enrollment_id,
            studentProfileId: $record->enrollment->student_profile_id,
        );
    }
}
