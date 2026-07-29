<?php

namespace App\Filament\Resources\OperationalEvents\Tables;

use App\Models\OperationalEvent;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class OperationalEventsTable
{
    /** @return array<string, string> */
    public static function statusColors(): array
    {
        return [
            OperationalEvent::StatusPending => 'warning',
            OperationalEvent::StatusProcessed => 'success',
            OperationalEvent::StatusFailed => 'danger',
            OperationalEvent::StatusReviewRequired => 'warning',
            OperationalEvent::StatusIgnored => 'gray',
        ];
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            OperationalEvent::StatusPending => 'Pending',
            OperationalEvent::StatusProcessed => 'Processed',
            OperationalEvent::StatusFailed => 'Failed',
            OperationalEvent::StatusReviewRequired => 'Review required',
            OperationalEvent::StatusIgnored => 'Ignored',
        ];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('event_domain')
                    ->label('Area')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? str((string) $state)->headline()->toString()
                        : 'System')
                    ->badge()
                    ->searchable(),
                TextColumn::make('integration')
                    ->label('Service')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? str((string) $state)->headline()->toString()
                        : 'Internal')
                    ->searchable()
                    ->placeholder('Internal'),
                TextColumn::make('channel')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('event_type')
                    ->label('Event')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? str((string) $state)->headline()->toString()
                        : 'Recorded event')
                    ->searchable(),
                TextColumn::make('status')
                    ->formatStateUsing(fn (?string $state): string => self::statusOptions()[$state] ?? str((string) $state)->headline()->toString())
                    ->badge()
                    ->color(fn (?string $state): string => self::statusColors()[$state] ?? 'gray'),
                TextColumn::make('occurred_at')
                    ->label('Occurred at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('failed_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('user.name')
                    ->label('Related User')
                    ->placeholder('System'),
            ])
            ->filters([
                SelectFilter::make('event_domain')
                    ->options(fn (): array => self::eventDomainOptions()),
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
                SelectFilter::make('integration')
                    ->options(fn (): array => self::integrationOptions()),
                Filter::make('occurred_at')
                    ->schema([
                        DatePicker::make('occurred_from')->label('Occurred from')->native(false),
                        DatePicker::make('occurred_until')->label('Occurred until')->native(false)->afterOrEqual('occurred_from'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['occurred_from'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('occurred_at', '>=', Carbon::parse($date)))
                            ->when($data['occurred_until'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('occurred_at', '<=', Carbon::parse($date)));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading('No operational events')
            ->emptyStateDescription('Integration and delivery events appear here when the system records them.')
            ->defaultSort('occurred_at', 'desc');
    }

    /** @return array<string, string> */
    private static function eventDomainOptions(): array
    {
        return [
            'notifications' => 'Notifications',
            OperationalEvent::DomainIntegration => 'Integration',
        ];
    }

    /** @return array<string, string> */
    private static function integrationOptions(): array
    {
        return [
            'mail' => 'Mail',
            OperationalEvent::IntegrationPayMongo => 'PayMongo',
            OperationalEvent::IntegrationSchedulingSolver => 'Scheduling Solver',
        ];
    }
}
