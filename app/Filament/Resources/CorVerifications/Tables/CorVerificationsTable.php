<?php

namespace App\Filament\Resources\CorVerifications\Tables;

use App\Actions\Registrar\CorVerificationLifecycleService;
use App\Models\CorVerification;
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

class CorVerificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term', 'enrollment']))
            ->columns([
                TextColumn::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable(),
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors(CorVerification::statusColors()),
                TextColumn::make('token')
                    ->copyable()
                    ->limit(16)
                    ->searchable(),
                TextColumn::make('issued_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('revoked_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CorVerification::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::supersedeAction(),
                self::revokeAction(),
            ])
            ->toolbarActions([]);
    }

    private static function supersedeAction(): Action
    {
        return Action::make('supersede')
            ->label('Supersede')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (CorVerification $record): bool => self::registrarCanControlCor()
                && $record->isValid())
            ->action(fn (CorVerification $record) => self::supersede($record));
    }

    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->schema([
                Textarea::make('reason')
                    ->required()
                    ->maxLength(500),
            ])
            ->requiresConfirmation()
            ->visible(fn (CorVerification $record): bool => self::registrarCanControlCor()
                && ! $record->isRevoked())
            ->action(fn (CorVerification $record, array $data) => self::revoke($record, (string) $data['reason']));
    }

    private static function supersede(CorVerification $record): void
    {
        self::runAction(
            callback: fn (CorVerificationLifecycleService $service, User $actor) => $service->supersede($record, $actor),
            successTitle: 'COR marked superseded',
        );
    }

    private static function revoke(CorVerification $record, string $reason): void
    {
        self::runAction(
            callback: fn (CorVerificationLifecycleService $service, User $actor) => $service->revoke($record, $actor, $reason),
            successTitle: 'COR revoked',
        );
    }

    /**
     * @param  callable(CorVerificationLifecycleService, User): CorVerification  $callback
     */
    private static function runAction(callable $callback, string $successTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $callback(app(CorVerificationLifecycleService::class), $actor);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('COR action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function registrarCanControlCor(): bool
    {
        return auth()->user()?->can('manage-cor-verifications') ?? false;
    }
}
