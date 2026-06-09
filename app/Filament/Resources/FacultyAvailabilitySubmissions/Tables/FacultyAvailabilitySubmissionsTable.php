<?php

namespace App\Filament\Resources\FacultyAvailabilitySubmissions\Tables;

use App\Actions\Scheduling\FacultyAvailabilityService;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class FacultyAvailabilitySubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['term', 'faculty', 'approver'])->withCount('windows'))
            ->columns([
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FacultyAvailabilitySubmission::statusOptions()[$state] ?? '-')
                    ->color(fn (?string $state): string => match ($state) {
                        FacultyAvailabilitySubmission::StatusSubmitted => 'warning',
                        FacultyAvailabilitySubmission::StatusLocked => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('version')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('windows_count')
                    ->label('Windows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('submitted_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('locked_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('approver.name')
                    ->label('Locked By')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(FacultyAvailabilitySubmission::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::lockAction(),
            ])
            ->toolbarActions([])
            ->defaultSort('submitted_at', 'desc');
    }

    private static function lockAction(): Action
    {
        return Action::make('lockAvailability')
            ->label('Lock')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Lock faculty availability')
            ->modalDescription('Locked availability becomes the scheduling input snapshot source. Faculty cannot directly edit it after locking.')
            ->visible(fn (FacultyAvailabilitySubmission $record): bool => self::canReviewAvailability()
                && $record->status === FacultyAvailabilitySubmission::StatusSubmitted)
            ->action(function (FacultyAvailabilitySubmission $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    app(FacultyAvailabilityService::class)->lockSubmission($record, $actor);

                    Notification::make()
                        ->title('Faculty availability locked')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Faculty availability was not locked')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private static function canReviewAvailability(): bool
    {
        return auth()->user()?->can('review-lock-faculty-availability') ?? false;
    }
}
