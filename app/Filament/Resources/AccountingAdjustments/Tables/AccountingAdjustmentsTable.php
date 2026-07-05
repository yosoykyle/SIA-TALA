<?php

namespace App\Filament\Resources\AccountingAdjustments\Tables;

use App\Models\AccountingAdjustment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
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
                TextColumn::make('studentProfile.student_number')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('term.label')
                    ->label('Term')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(function (?int $state, AccountingAdjustment $record): string {
                        $enrollment = $record->enrollment;

                        return $enrollment instanceof Enrollment
                            ? AccountingAdjustment::enrollmentOptionLabel($enrollment)
                            : '-';
                    })
                    ->placeholder('-'),
                TextColumn::make('adjustment_type')
                    ->label('Type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('ledgerEntry.direction')
                    ->label('Posted Direction')
                    ->badge()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('ledgerEntry.amount')
                    ->label('Posted Amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('sourceLedgerEntry.id')
                    ->label('Source')
                    ->formatStateUsing(function (?int $state, AccountingAdjustment $record): string {
                        $ledgerEntry = $record->sourceLedgerEntry;

                        return $ledgerEntry instanceof LedgerEntry
                            ? AccountingAdjustment::sourceLedgerOptionLabel($ledgerEntry)
                            : '-';
                    })
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ledgerEntry.id')
                    ->label('Posted Entry')
                    ->formatStateUsing(function (?int $state, AccountingAdjustment $record): string {
                        $ledgerEntry = $record->ledgerEntry;

                        return $ledgerEntry instanceof LedgerEntry
                            ? AccountingAdjustment::sourceLedgerOptionLabel($ledgerEntry)
                            : '-';
                    })
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
