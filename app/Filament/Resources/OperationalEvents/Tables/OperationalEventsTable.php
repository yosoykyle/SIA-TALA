<?php

namespace App\Filament\Resources\OperationalEvents\Tables;

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
    /**
     * Status vocabulary source: `TAL75ReportsAuditTest.php` (`'status' =>
     * 'PROCESSED'`), the only existing writer of `OperationalEvent::status`
     * before this slice, plus the model's own `failed_at` column. Coloring
     * follows the same keyword convention already used by
     * `App\Filament\Pages\ReportsAudit::badgeColor()` for other status-like
     * columns in this codebase.
     *
     * @return array<string, string>
     */
    public static function statusColors(): array
    {
        return [
            'PROCESSED' => 'success',
            'FAILED' => 'danger',
            'PENDING' => 'warning',
        ];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('event_domain')
                    ->badge()
                    ->searchable(),
                TextColumn::make('integration')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('channel')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('event_type')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => self::statusColors()[$state] ?? 'gray'),
                TextColumn::make('occurred_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('failed_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('user.name')
                    ->label('Actor')
                    ->placeholder('System'),
            ])
            ->filters([
                SelectFilter::make('event_domain')
                    ->options(fn (): array => self::eventDomainOptions()),
                SelectFilter::make('status')
                    ->options(self::statusColors()),
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
            ->defaultSort('occurred_at', 'desc');
    }

    /** @return array<string, string> */
    private static function eventDomainOptions(): array
    {
        return [
            'notifications' => 'Notifications',
            'INTEGRATION' => 'Integration',
        ];
    }

    /** @return array<string, string> */
    private static function integrationOptions(): array
    {
        return [
            'mail' => 'Mail',
            'PAYMONGO' => 'PayMongo',
        ];
    }
}
