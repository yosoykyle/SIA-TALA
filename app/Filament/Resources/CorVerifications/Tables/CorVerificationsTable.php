<?php

namespace App\Filament\Resources\CorVerifications\Tables;

use App\Models\CorVerification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
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
                    ->colors([
                        'success' => CorVerification::StatusValid,
                        'warning' => CorVerification::StatusSuperseded,
                        'danger' => CorVerification::StatusRevoked,
                    ]),
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
                    ->options([
                        CorVerification::StatusValid => 'Valid',
                        CorVerification::StatusSuperseded => 'Superseded',
                        CorVerification::StatusRevoked => 'Revoked',
                    ]),
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
                && $record->status === CorVerification::StatusValid)
            ->action(fn (CorVerification $record) => self::transition(
                $record,
                CorVerification::StatusSuperseded,
                'cor_superseded',
                'COR marked superseded',
            ));
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
                && $record->status !== CorVerification::StatusRevoked)
            ->action(fn (CorVerification $record, array $data) => self::transition(
                $record,
                CorVerification::StatusRevoked,
                'cor_revoked',
                'COR revoked',
                (string) $data['reason'],
            ));
    }

    private static function transition(
        CorVerification $record,
        string $status,
        string $event,
        string $successTitle,
        ?string $reason = null,
    ): void {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            DB::transaction(function () use ($record, $status, $event, $actor, $reason): void {
                $timestamp = CarbonImmutable::now(config('app.timezone'));

                $record->forceFill([
                    'status' => $status,
                    'revoked_at' => $status === CorVerification::StatusRevoked ? $timestamp : $record->revoked_at,
                    'revocation_reason' => $status === CorVerification::StatusRevoked ? $reason : $record->revocation_reason,
                ])->save();

                DB::table('activity_log')->insert([
                    'log_name' => 'cor_controls',
                    'description' => 'COR verification state changed.',
                    'subject_type' => CorVerification::class,
                    'subject_id' => $record->id,
                    'event' => $event,
                    'causer_type' => User::class,
                    'causer_id' => $actor->id,
                    'properties' => json_encode([
                        'status_after' => $status,
                        'reason' => $reason,
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => $timestamp->toDateTimeString(),
                    'updated_at' => $timestamp->toDateTimeString(),
                ]);
            });

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
        return auth()->user()?->can('manage-lis') ?? false;
    }
}
