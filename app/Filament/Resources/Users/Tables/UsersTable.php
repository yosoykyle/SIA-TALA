<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

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
                    ->visible(fn ($record): bool => auth()->user()?->can('manage-users') && $record->status !== 'archived' && $record->id !== auth()->id())
                    ->action(function (array $data, $record): void {
                        DB::transaction(function () use ($data, $record): void {
                            $record->forceFill([
                                'status' => 'archived',
                                'archived_at' => now(),
                                'archived_reason' => $data['reason'],
                            ])->save();

                            $record->syncRoles([]);

                            activity()
                                ->performedOn($record)
                                ->causedBy(auth()->user())
                                ->event('staff_account_archived')
                                ->withProperties(['reason' => $data['reason']])
                                ->log('Staff account archived');
                        });

                        Notification::make()
                            ->title('Staff account archived')
                            ->success()
                            ->send();
                    }),
                Action::make('restore')
                    ->label('Restore Account')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->schema([
                        Select::make('role')
                            ->label('Restored staff role')
                            ->options([
                                'registrar' => 'Registrar',
                                'accounting' => 'Accounting',
                                'faculty' => 'Faculty',
                                'academic-head' => 'Academic Head',
                                'system-super-admin' => 'System Super Admin',
                            ])
                            ->required()
                            ->helperText('Restored accounts require exactly one active staff role.'),
                    ])
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => auth()->user()?->can('manage-users') && $record->status === 'archived')
                    ->action(function (array $data, $record): void {
                        DB::transaction(function () use ($data, $record): void {
                            $record->forceFill([
                                'status' => 'active',
                                'archived_at' => null,
                                'archived_reason' => null,
                            ])->save();

                            $record->syncRoles([$data['role']]);

                            activity()
                                ->performedOn($record)
                                ->causedBy(auth()->user())
                                ->event('staff_account_restored')
                                ->withProperties(['role' => $data['role']])
                                ->log('Staff account restored');
                        });

                        Notification::make()
                            ->title('Staff account restored')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
