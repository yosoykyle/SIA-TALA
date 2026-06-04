<?php

namespace App\Filament\Resources\PromissoryNotes\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PromissoryNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term', 'enrollment', 'ledgerEntry', 'approver']))
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
                TextColumn::make('ledgerEntry.id')
                    ->label('Ledger')
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Due')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('approver.name')
                    ->label('Approved By')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('approved_at')
                    ->label('Approved')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expired_at')
                    ->label('Expired')
                    ->dateTime()
                    ->sortable(),
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
                SelectFilter::make('status')
                    ->options([
                        'approved' => 'Approved',
                        'active' => 'Active',
                        'expired' => 'Expired',
                        'settled' => 'Settled',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
