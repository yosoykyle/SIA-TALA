<?php

namespace App\Filament\Resources\FinancialAccommodations\Tables;

use App\Models\FinancialAccommodation;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FinancialAccommodationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'term', 'recorder']))
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
                    ->searchable(),
                TextColumn::make('covered_amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('basis')
                    ->formatStateUsing(fn (string $state): string => FinancialAccommodation::basisOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())
                    ->badge()
                    ->searchable(),
                TextColumn::make('status')
                    ->formatStateUsing(fn (string $state): string => FinancialAccommodation::statusOptions()[$state] ?? str($state)->headline()->toString())
                    ->badge()
                    ->searchable()
                    ->sortable(),
                IconColumn::make('allows_finance_gate')
                    ->label('Finance Gate')
                    ->boolean(),
                IconColumn::make('allows_next_term_enrollment')
                    ->label('Next Term')
                    ->boolean(),
                IconColumn::make('allows_record_release')
                    ->label('Records')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('waives_downpayment')
                    ->label('Waives DP')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_on')
                    ->date()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('recorder.name')
                    ->label('Recorded By')
                    ->placeholder('System')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(FinancialAccommodation::statusOptions()),
                SelectFilter::make('basis')
                    ->options(FinancialAccommodation::basisOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
