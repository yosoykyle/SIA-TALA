<?php

namespace App\Filament\Resources\PaymentAttempts\Tables;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\PaymentAttempt;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentAttemptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'assessment.enrollment.term']))
            ->columns([
                TextColumn::make('studentProfile.student_number')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('assessment.enrollment.term.label')
                    ->label('Term')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('assessment.enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(function (?int $state, PaymentAttempt $record): string {
                        $assessment = $record->assessment;
                        $enrollment = $assessment instanceof Assessment ? $assessment->enrollment : null;

                        return $enrollment instanceof Enrollment ? $enrollment->displayLabel() : '-';
                    })
                    ->placeholder('-'),
                TextColumn::make('channel')
                    ->badge()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('provider')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('internal_reference')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_checkout_id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_intent_id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->label('Paid')
                    ->dateTime()
                    ->sortable(),
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
                        'pending' => 'Pending',
                        'paid' => 'Paid',
                        'failed' => 'Failed',
                        'expired' => 'Expired',
                    ]),
                SelectFilter::make('channel')
                    ->options([
                        'checkout' => 'Checkout',
                        'gcash' => 'GCash',
                        'card' => 'Card',
                        'cash' => 'Cash',
                    ]),
                SelectFilter::make('provider')
                    ->options([
                        'mock' => 'Mock',
                        'paymongo' => 'PayMongo',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
