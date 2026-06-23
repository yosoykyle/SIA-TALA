<?php

namespace App\Filament\Resources\EnrollmentSubjects\Tables;

use App\Actions\Faculty\FacultyClassListService;
use App\Actions\Grades\GradeEncodingService;
use App\Actions\Grades\GradeSubmissionPackageService;
use App\Models\EnrollmentSubject;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

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
                'grade',
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
                TextColumn::make('grade.prelim_grade')
                    ->label('Prelim/Q1')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('grade.midterm_grade')
                    ->label('Midterm/Q2')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('grade.final_grade')
                    ->label('Final Raw')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('grade.grade')
                    ->label('Final Grade')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('grade.remarks')
                    ->label('Remarks')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('grade.is_finalized')
                    ->label('Submission')
                    ->badge()
                    ->state(fn (EnrollmentSubject $record): string => $record->grade?->is_finalized ? 'finalized' : 'draft')
                    ->color(fn (string $state): string => $state === 'finalized' ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('submission')
                    ->label('Submission')
                    ->options([
                        'draft' => 'Draft / No Grade',
                        'finalized' => 'Finalized',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? null) {
                            'draft' => $query->where(function ($draftQuery): void {
                                $draftQuery
                                    ->whereDoesntHave('grade')
                                    ->orWhereHas('grade', fn ($gradeQuery) => $gradeQuery->where('is_finalized', false));
                            }),
                            'finalized' => $query->whereHas('grade', fn ($gradeQuery) => $gradeQuery->where('is_finalized', true)),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                self::encodeGradeAction(),
                self::markIncompleteAction(),
                self::submitGradePackageAction(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    private static function encodeGradeAction(): Action
    {
        return Action::make('encodeGrade')
            ->label('Encode Grade')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->modalHeading('Encode College Grade')
            ->modalDescription('Enter College raw period scores. TALA averages the raw scores first, then transmutes once.')
            ->schema([
                TextInput::make('prelim')
                    ->label('College Prelim Raw Score')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100),
                TextInput::make('midterm')
                    ->label('College Midterm Raw Score')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100),
                TextInput::make('final')
                    ->label('College Final Raw Score')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100),
            ])
            ->modalSubmitActionLabel('Save Grade Draft')
            ->visible(fn (EnrollmentSubject $record): bool => auth()->user()?->can('encodeGrade', $record) ?? false)
            ->action(function (EnrollmentSubject $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleGradeAction(function () use ($record, $data, $actor): void {
                    app(GradeEncodingService::class)->encode(
                        enrollmentSubjectId: $record->id,
                        periodGrades: self::periodPayload($record, $data),
                        actor: $actor,
                    );
                }, 'Grade draft saved');
            });
    }

    private static function markIncompleteAction(): Action
    {
        return Action::make('markIncomplete')
            ->label('Mark INC')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (EnrollmentSubject $record): bool => auth()->user()?->can('markIncomplete', $record) ?? false)
            ->action(function (EnrollmentSubject $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleGradeAction(function () use ($record, $actor): void {
                    app(GradeEncodingService::class)->markIncomplete(
                        enrollmentSubjectId: $record->id,
                        actor: $actor,
                    );
                }, 'Subject marked incomplete');
            });
    }

    private static function submitGradePackageAction(): Action
    {
        return Action::make('submitGradePackage')
            ->label('Submit Package')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Submit Grade Package')
            ->modalDescription('Submit this section and subject grade package for Registrar verification. Faculty editing locks until Registrar returns the package.')
            ->visible(fn (EnrollmentSubject $record): bool => auth()->user()?->can('submitGradePackage', $record) ?? false)
            ->action(function (EnrollmentSubject $record): void {
                $actor = auth()->user();

                $record->loadMissing('enrollment');

                if (! $actor instanceof User || $record->grade === null || $record->enrollment?->section_id === null) {
                    return;
                }

                self::handleGradeAction(function () use ($record, $actor): void {
                    app(GradeSubmissionPackageService::class)->submit(
                        termId: $record->enrollment->term_id,
                        sectionId: $record->enrollment->section_id,
                        subjectId: $record->subject_id,
                        faculty: $actor,
                    );
                }, 'Grade package submitted for Registrar verification');
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function periodPayload(EnrollmentSubject $record, array $data): array
    {
        return [
            'prelim' => $data['prelim'] ?? null,
            'midterm' => $data['midterm'] ?? null,
            'final' => $data['final'] ?? null,
        ];
    }

    private static function financeStatus(EnrollmentSubject $record): string
    {
        $record->loadMissing('enrollment.studentProfile');

        return app(FacultyClassListService::class)->facultyPaymentStatusFor(
            enrollmentId: $record->enrollment_id,
            studentProfileId: $record->enrollment->student_profile_id,
        );
    }

    /**
     * @param  callable(): void  $callback
     */
    private static function handleGradeAction(callable $callback, string $successTitle): void
    {
        try {
            $callback();

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Grade action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
