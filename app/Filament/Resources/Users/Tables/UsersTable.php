<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\SystemAdministration\UserAccountLifecycleService;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('username')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->separator(', ')
                    ->toggleable(),
                TextColumn::make('archived_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record): bool => auth()->user()?->can('update', $record)),
                Action::make('archive')
                    ->label('Archive Account')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Official archive reason')
                            ->required()
                            ->minLength(10)
                            ->columnSpanFull(),
                    ])
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => (auth()->user()?->can('archiveStaffAccount', $record) ?? false)
                        && $record->status !== User::StatusArchived)
                    ->action(function (array $data, $record): void {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return;
                        }

                        try {
                            app(UserAccountLifecycleService::class)->archive(
                                target: $record,
                                actor: $actor,
                                reason: (string) $data['reason'],
                            );

                            Notification::make()
                                ->title('Staff account archived')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Staff account archive failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('restore')
                    ->label('Restore Account')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->schema([
                        Select::make('role')
                            ->label('Restored staff role')
                            ->options(User::staffRoleOptions())
                            ->required()
                            ->helperText('Restored accounts require exactly one active staff role.'),
                    ])
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => (auth()->user()?->can('restoreStaffAccount', $record) ?? false)
                        && $record->status === User::StatusArchived)
                    ->action(function (array $data, $record): void {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return;
                        }

                        try {
                            app(UserAccountLifecycleService::class)->restore(
                                target: $record,
                                actor: $actor,
                                role: (string) $data['role'],
                            );

                            Notification::make()
                                ->title('Staff account restored')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Staff account restore failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }
}
