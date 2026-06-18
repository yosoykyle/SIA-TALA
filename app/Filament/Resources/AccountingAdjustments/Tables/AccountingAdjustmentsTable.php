<?php

namespace App\Filament\Resources\AccountingAdjustments\Tables;

use App\Models\AccountingAdjustment;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccountingAdjustmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term', 'enrollment', 'sourceLedgerEntry', 'ledgerEntry', 'poster']))
            ->columns([
                TextColumn::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(fn (?int $state, AccountingAdjustment $record): string => $record->enrollment === null
                        ? '-'
                        : AccountingAdjustment::enrollmentOptionLabel($record->enrollment))
                    ->placeholder('-'),
                TextColumn::make('adjustment_type')
                    ->label('Type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('ledgerEntry.running_balance')
                    ->label('Balance After')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('sourceLedgerEntry.id')
                    ->label('Source')
                    ->formatStateUsing(fn (?int $state, AccountingAdjustment $record): string => $record->sourceLedgerEntry === null
                        ? '-'
                        : AccountingAdjustment::sourceLedgerOptionLabel($record->sourceLedgerEntry))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ledgerEntry.id')
                    ->label('Posted Entry')
                    ->formatStateUsing(fn (?int $state, AccountingAdjustment $record): string => $record->ledgerEntry === null
                        ? '-'
                        : AccountingAdjustment::sourceLedgerOptionLabel($record->ledgerEntry))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('evidence_reference')
                    ->label('Evidence')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('posted_at')
                    ->label('Posted')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('poster.name')
                    ->label('Posted By')
                    ->placeholder('System')
                    ->searchable(),
                TextColumn::make('reason')
                    ->limit(40)
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
                SelectFilter::make('adjustment_type')
                    ->label('Type')
                    ->options(AccountingAdjustment::typeOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
