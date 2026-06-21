<?php

namespace App\Filament\Resources\LedgerEntries\Tables;

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
                    ->placeholder('-'),
                TextColumn::make('entry_type')
                    ->label('Type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('reference_type')
                    ->label('Reference')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('reference_id')
                    ->label('Ref ID')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('description')
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('running_balance')
                    ->label('Balance')
                    ->money('PHP')
                    ->sortable(),
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
                SelectFilter::make('entry_type')
                    ->label('Type')
                    ->options([
                        'assessment' => 'Assessment',
                        'discount' => 'Discount',
                        'payment' => 'Payment',
                        'penalty' => 'Penalty',
                        'accounting_adjustment' => 'Accounting Adjustment',
                    ]),
                SelectFilter::make('reference_type')
                    ->label('Reference')
                    ->options([
                        'fee_template' => 'Fee Template',
                        'payment' => 'Payment',
                        'accounting_adjustment' => 'Accounting Adjustment',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
