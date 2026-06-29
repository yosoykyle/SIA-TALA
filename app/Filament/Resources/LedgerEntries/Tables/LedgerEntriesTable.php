<?php

namespace App\Filament\Resources\LedgerEntries\Tables;

use App\Models\LedgerEntry;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LedgerEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term', 'enrollment', 'poster']))
            ->columns([
                TextColumn::make('studentProfile.student_number')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.last_name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('term.label')
                    ->label('Term')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('enrollment.id')
                    ->label('Enrollment')
                    ->placeholder('-'),
                TextColumn::make('direction')
                    ->badge()
                    ->searchable(),
                TextColumn::make('category')
                    ->badge()
                    ->searchable(),
                TextColumn::make('source_type')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_id')
                    ->numeric(),
                TextColumn::make('description')
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('state')
                    ->badge(),
                TextColumn::make('posted_at')
                    ->label('Posted')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('poster.name')
                    ->label('Posted By')
                    ->placeholder('System')
                    ->searchable(),
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
                SelectFilter::make('direction')
                    ->options([
                        LedgerEntry::DirectionCharge => 'Charge',
                        LedgerEntry::DirectionPenalty => 'Penalty',
                        LedgerEntry::DirectionPayment => 'Payment',
                        LedgerEntry::DirectionDiscount => 'Discount',
                        LedgerEntry::DirectionAdjustment => 'Adjustment',
                        LedgerEntry::DirectionReversal => 'Reversal',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
