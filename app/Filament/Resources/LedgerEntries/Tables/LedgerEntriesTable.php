<?php

namespace App\Filament\Resources\LedgerEntries\Tables;

use App\Models\LedgerEntry;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LedgerEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'studentProfile.user',
                'term.academicYear',
                'enrollment',
                'poster',
                'payment',
                'reversedEntry',
                'adjustedEntry',
            ]))
            ->columns([
                TextColumn::make('studentProfile.student_number')
                    ->label('Student No.')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('term.label')
                    ->label('Term')
                    ->description(fn (LedgerEntry $record): ?string => $record->term?->academicYear?->label)
                    ->placeholder('Not term-specific')
                    ->searchable(),
                TextColumn::make('posted_at')
                    ->label('Posted')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('effect')
                    ->label('Balance Effect')
                    ->state(fn (LedgerEntry $record): string => $record->effectLabel())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Increases balance' => 'warning',
                        'Reduces balance' => 'success',
                        'Reverses a prior entry' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('category')
                    ->label('Category')
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                    ->badge()
                    ->searchable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->state(fn (LedgerEntry $record): string => $record->sourceLabel())
                    ->wrap(),
                TextColumn::make('payment.channel')
                    ->label('Payment Method')
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? str($state)->replace('_', ' ')->headline()->toString()
                        : 'Not applicable')
                    ->placeholder('Not applicable'),
                TextColumn::make('description')
                    ->label('Reason / Description')
                    ->limit(60)
                    ->tooltip(fn (LedgerEntry $record): string => $record->description)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('amount')
                    ->label('Recorded Amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Posting State')
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                    ->badge(),
                TextColumn::make('poster.name')
                    ->label('Posted By')
                    ->placeholder('System')
                    ->searchable(),
                TextColumn::make('reversal_trace')
                    ->label('Correction Trace')
                    ->state(fn (LedgerEntry $record): string => match (true) {
                        $record->reverses_entry_id !== null => "Reverses activity #{$record->reverses_entry_id}",
                        $record->adjusts_entry_id !== null => "Adjusts activity #{$record->adjusts_entry_id}",
                        default => 'Original activity',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label('Technical Activity ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_id')
                    ->label('Technical Source ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Recorded At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('student_profile_id')
                    ->label('Student')
                    ->relationship('studentProfile', 'student_number')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('term_id')
                    ->label('Term')
                    ->relationship('term', 'label')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('direction')
                    ->label('Balance Effect')
                    ->options([
                        LedgerEntry::DirectionCharge => 'Charge',
                        LedgerEntry::DirectionPenalty => 'Penalty',
                        LedgerEntry::DirectionPayment => 'Payment',
                        LedgerEntry::DirectionDiscount => 'Discount',
                        LedgerEntry::DirectionScholarship => 'Scholarship',
                        LedgerEntry::DirectionWaiver => 'Waiver',
                        LedgerEntry::DirectionRefund => 'Refund',
                        LedgerEntry::DirectionAdjustment => 'Adjustment',
                        LedgerEntry::DirectionReversal => 'Reversal',
                    ]),
                SelectFilter::make('category')
                    ->options(fn (): array => self::distinctOptions('category')),
                SelectFilter::make('state')
                    ->label('Posting State')
                    ->options(fn (): array => self::distinctOptions('state')),
                SelectFilter::make('source_type')
                    ->label('Source')
                    ->options(fn (): array => LedgerEntry::query()
                        ->whereNotNull('source_type')
                        ->distinct()
                        ->orderBy('source_type')
                        ->pluck('source_type', 'source_type')
                        ->map(fn (string $source): string => str(class_basename($source))->headline()->toString())
                        ->all()),
                Filter::make('corrections')
                    ->label('Adjustments and reversals only')
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $corrections): Builder => $corrections
                            ->whereNotNull('adjusts_entry_id')
                            ->orWhereNotNull('reverses_entry_id'),
                    ))
                    ->toggle(),
            ])
            ->recordActions([
                ViewAction::make()->label('View Activity'),
            ])
            ->defaultSort('posted_at', 'desc')
            ->emptyStateHeading('No account activity yet')
            ->emptyStateDescription('Charges, verified payments, adjustments, and reversals appear here after they are posted.')
            ->toolbarActions([]);
    }

    /** @return array<string, string> */
    private static function distinctOptions(string $column): array
    {
        return LedgerEntry::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->map(fn (string $value): string => str($value)->replace('_', ' ')->headline()->toString())
            ->all();
    }
}
