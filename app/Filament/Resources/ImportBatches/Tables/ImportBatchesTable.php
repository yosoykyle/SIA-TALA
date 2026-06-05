<?php

namespace App\Filament\Resources\ImportBatches\Tables;

use App\Actions\Imports\ImportBatchLifecycleService;
use App\Models\ImportBatch;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class ImportBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['importer', 'committer']))
            ->columns([
                TextColumn::make('import_type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('file_name')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors(ImportBatch::statusColors()),
                TextColumn::make('total_rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('valid_rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('error_rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('skipped_rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('importer.name')
                    ->label('Uploaded By')
                    ->placeholder('-'),
                TextColumn::make('committer.name')
                    ->label('Committed By')
                    ->placeholder('-'),
                TextColumn::make('committed_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('import_type')
                    ->options(ImportBatch::importTypeOptions()),
                SelectFilter::make('status')
                    ->options(ImportBatch::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::commitAction(),
                self::cancelAction(),
            ])
            ->toolbarActions([]);
    }

    private static function commitAction(): Action
    {
        return Action::make('commit')
            ->label('Commit')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (ImportBatch $record): bool => self::registrarCanManageImports()
                && $record->isPendingReview())
            ->action(fn (ImportBatch $record) => self::commit($record));
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (ImportBatch $record): bool => self::registrarCanManageImports()
                && $record->isPendingReview())
            ->action(fn (ImportBatch $record) => self::cancel($record));
    }

    private static function commit(ImportBatch $record): void
    {
        self::transition($record, 'commit', 'Import batch committed');
    }

    private static function cancel(ImportBatch $record): void
    {
        self::transition($record, 'cancel', 'Import batch cancelled');
    }

    private static function transition(ImportBatch $record, string $method, string $successTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            app(ImportBatchLifecycleService::class)->{$method}($record, $actor);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Import batch action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function registrarCanManageImports(): bool
    {
        $user = auth()->user();

        return ($user?->can('manage-curricula') ?? false)
            || ($user?->can('manage-schedules') ?? false)
            || ($user?->can('evaluate-transferees') ?? false);
    }
}
