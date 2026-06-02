<?php

namespace App\Filament\Resources\Grades\Tables;

use App\Actions\Grades\GradeFinalizationService;
use App\Models\Grade;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class GradesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'enrollment.studentProfile.user',
                'enrollmentSubject',
                'subject',
                'term',
                'faculty',
                'finalizedBy',
                'reopenedBy',
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
                TextColumn::make('subject.code')
                    ->label('Subject')
                    ->searchable(),
                TextColumn::make('subject.description')
                    ->label('Description')
                    ->limit(35)
                    ->toggleable(),
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->searchable(),
                TextColumn::make('prelim_grade')
                    ->label('Prelim/Q1')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('midterm_grade')
                    ->label('Midterm/Q2')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('final_grade')
                    ->label('Final Raw')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('grade')
                    ->label('Final Grade')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('remarks')
                    ->badge()
                    ->searchable(),
                IconColumn::make('is_inc')
                    ->label('INC')
                    ->boolean(),
                TextColumn::make('inc_expires_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                IconColumn::make('is_finalized')
                    ->label('Finalized')
                    ->boolean(),
                TextColumn::make('finalizedBy.name')
                    ->label('Finalized By')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('finalized_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('reopenedBy.name')
                    ->label('Reopened By')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('reopened_at')
                    ->dateTime()
                    ->placeholder('-')
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
            ->filters([
                SelectFilter::make('submission')
                    ->label('Submission')
                    ->options([
                        'draft' => 'Draft',
                        'finalized' => 'Finalized',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? null) {
                            'draft' => $query->where('is_finalized', false),
                            'finalized' => $query->where('is_finalized', true),
                            default => $query,
                        };
                    }),
                SelectFilter::make('term_id')
                    ->label('Term')
                    ->relationship('term', 'term_name'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::forceFinalizeAction(),
                self::reopenAction(),
            ])
            ->toolbarActions([])
            ->defaultSort('updated_at', 'desc');
    }

    private static function forceFinalizeAction(): Action
    {
        return Action::make('forceFinalize')
            ->label('Force Finalize')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('warning')
            ->schema([
                Textarea::make('reason')
                    ->label('Override Reason')
                    ->required()
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Force Finalize Grade')
            ->visible(fn (Grade $record): bool => auth()->user()?->can('forceFinalize', $record) ?? false)
            ->action(function (Grade $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleGradeOverride(function () use ($record, $data, $actor): void {
                    app(GradeFinalizationService::class)->forceFinalize(
                        grade: $record,
                        actor: $actor,
                        reason: (string) $data['reason'],
                    );
                }, 'Grade force-finalized by Academic Head override');
            });
    }

    private static function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label('Reopen Grade')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('danger')
            ->schema([
                Textarea::make('reason')
                    ->label('Override Reason')
                    ->required()
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Reopen Grade')
            ->visible(fn (Grade $record): bool => auth()->user()?->can('reopen', $record) ?? false)
            ->action(function (Grade $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handleGradeOverride(function () use ($record, $data, $actor): void {
                    app(GradeFinalizationService::class)->reopen(
                        grade: $record,
                        actor: $actor,
                        reason: (string) $data['reason'],
                    );
                }, 'Grade reopened by Academic Head override');
            });
    }

    /**
     * @param  callable(): void  $callback
     */
    private static function handleGradeOverride(callable $callback, string $successTitle): void
    {
        try {
            $callback();

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Grade override failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
