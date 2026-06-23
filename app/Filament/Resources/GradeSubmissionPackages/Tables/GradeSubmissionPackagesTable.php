<?php

namespace App\Filament\Resources\GradeSubmissionPackages\Tables;

use App\Actions\Grades\GradeSubmissionPackageService;
use App\Models\GradeSubmissionPackage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class GradeSubmissionPackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('items'))
            ->columns([
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('section.name')
                    ->label('Section')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject.code')
                    ->label('Subject')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject.description')
                    ->label('Description')
                    ->limit(35)
                    ->toggleable(),
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        GradeSubmissionPackage::StateSubmitted => 'warning',
                        GradeSubmissionPackage::StateReturned => 'danger',
                        GradeSubmissionPackage::StateVerifiedFinalized => 'success',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('items_count')
                    ->label('Rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('submittedBy.name')
                    ->label('Submitted By')
                    ->toggleable(),
                TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('registrarReviewer.name')
                    ->label('Registrar Reviewer')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('registrar_reviewed_at')
                    ->label('Reviewed At')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('return_reason')
                    ->label('Return Reason')
                    ->limit(45)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options([
                        GradeSubmissionPackage::StateSubmitted => 'Submitted',
                        GradeSubmissionPackage::StateReturned => 'Returned',
                        GradeSubmissionPackage::StateVerifiedFinalized => 'Verified Finalized',
                    ]),
                SelectFilter::make('term_id')
                    ->label('Term')
                    ->relationship('term', 'term_name'),
            ])
            ->recordActions([
                ViewAction::make(),
                self::returnForRevisionAction(),
                self::verifyAndFinalizeAction(),
            ])
            ->toolbarActions([])
            ->defaultSort('submitted_at', 'desc');
    }

    private static function returnForRevisionAction(): Action
    {
        return Action::make('returnForRevision')
            ->label('Return')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->schema([
                Textarea::make('reason')
                    ->label('Return Reason')
                    ->required()
                    ->maxLength(500),
            ])
            ->modalHeading('Return Grade Package')
            ->modalSubmitActionLabel('Return Package')
            ->visible(fn (GradeSubmissionPackage $record): bool => auth()->user()?->can('returnForRevision', $record) ?? false)
            ->action(function (GradeSubmissionPackage $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handlePackageAction(function () use ($record, $data, $actor): void {
                    app(GradeSubmissionPackageService::class)->returnForRevision(
                        package: $record,
                        registrar: $actor,
                        reason: (string) $data['reason'],
                    );
                }, 'Grade package returned for revision');
            });
    }

    private static function verifyAndFinalizeAction(): Action
    {
        return Action::make('verifyAndFinalize')
            ->label('Verify & Finalize')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Verify and Finalize Grade Package')
            ->modalDescription('This finalizes all grade rows in the submitted package and releases them as official grade history.')
            ->modalSubmitActionLabel('Verify and Finalize')
            ->visible(fn (GradeSubmissionPackage $record): bool => auth()->user()?->can('verifyAndFinalize', $record) ?? false)
            ->action(function (GradeSubmissionPackage $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                self::handlePackageAction(function () use ($record, $actor): void {
                    app(GradeSubmissionPackageService::class)->verifyAndFinalize(
                        package: $record,
                        registrar: $actor,
                    );
                }, 'Grade package verified and finalized');
            });
    }

    /**
     * @param  callable(): void  $callback
     */
    private static function handlePackageAction(callable $callback, string $successTitle): void
    {
        try {
            $callback();

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Grade package action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
