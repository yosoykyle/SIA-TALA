<?php

namespace App\Filament\Resources\ImportBatches\Tables;

use App\Actions\Imports\ImportBatchLifecycleService;
use App\Filament\Resources\ImportBatches\ImportBatchDownloadActions;
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
            ->modifyQueryUsing(fn ($query) => $query->with('uploader'))
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ImportBatch::importTypeOptions()[$state] ?? str((string) $state)->headline()->toString())
                    ->searchable(),
                TextColumn::make('template_version')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('state')
                    ->badge()
                    ->color(fn (?string $state): string => ImportBatch::statusColors()[$state] ?? 'gray')
                    ->formatStateUsing(fn (?string $state): string => ImportBatch::statusOptions()[$state] ?? str((string) $state)->headline()->toString()),
                TextColumn::make('row_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('error_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('warning_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('uploader.name')
                    ->label('Uploaded By')
                    ->placeholder('-'),
                TextColumn::make('acknowledged_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('posted_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(ImportBatch::importTypeOptions()),
                SelectFilter::make('state')
                    ->options(ImportBatch::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                ImportBatchDownloadActions::validationFindings(),
                ImportBatchDownloadActions::source(),
                self::acknowledgeWarningsAction(),
                self::postAction(),
                self::cancelAction(),
            ])
            ->toolbarActions([]);
    }

    private static function acknowledgeWarningsAction(): Action
    {
        return Action::make('acknowledgeWarnings')
            ->label('Acknowledge warnings')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('warning')
            ->requiresConfirmation()
            ->authorize('update')
            ->visible(fn (ImportBatch $record): bool => self::registrarCanManageImports()
                && $record->isPendingReview()
                && (int) $record->warning_count > 0
                && $record->acknowledged_at === null)
            ->action(fn (ImportBatch $record) => self::transition($record, 'acknowledgeWarnings', 'Import warnings acknowledged'));
    }

    private static function postAction(): Action
    {
        return Action::make('post')
            ->label('Create Draft records')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('success')
            ->requiresConfirmation()
            ->authorize('update')
            ->visible(fn (ImportBatch $record): bool => self::registrarCanManageImports()
                && $record->isPendingReview()
                && (int) $record->error_count === 0
                && ((int) $record->warning_count === 0 || $record->acknowledged_at !== null))
            ->action(fn (ImportBatch $record) => self::transition($record, 'post', 'Draft records created'));
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->requiresConfirmation()
            ->authorize('update')
            ->visible(fn (ImportBatch $record): bool => self::registrarCanManageImports()
                && $record->isPendingReview())
            ->action(fn (ImportBatch $record) => self::transition($record, 'cancel', 'Import batch cancelled'));
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
        return auth()->user()?->can('manage', ImportBatch::class) ?? false;
    }
}
