<?php

namespace App\Filament\Resources\PaymentAttempts\Tables;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\PaymentAttempt;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

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

                        return $enrollment instanceof Enrollment ? self::enrollmentLabel($enrollment) : '-';
                    })
                    ->placeholder('-'),
                TextColumn::make('channel')
                    ->formatStateUsing(fn (?string $state): string => self::paymentChannelLabel($state))
                    ->badge()
                    ->searchable(),
                TextColumn::make('status')
                    ->formatStateUsing(fn (?string $state): string => self::humanizeCode($state))
                    ->badge()
                    ->searchable(),
                TextColumn::make('provider')
                    ->formatStateUsing(fn (?string $state): string => self::paymentProviderLabel($state))
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
                TextColumn::make('expires_at')
                    ->label('Provider Expiry')
                    ->dateTime()
                    ->placeholder('-')
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
                        'pending' => 'Pending',
                        'under_review' => 'Under Review',
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
                        'online_checkout' => 'Online Checkout',
                        'synthetic_acceptance' => 'Acceptance Fixture',
                    ]),
                SelectFilter::make('provider')
                    ->options([
                        'mock' => 'Mock',
                        'paymongo' => 'PayMongo',
                        'synthetic_acceptance' => 'Acceptance Fixture',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    private static function enrollmentLabel(Enrollment $enrollment): string
    {
        $enrollment->loadMissing('term');

        return collect([
            "#{$enrollment->id}",
            $enrollment->term->label,
            self::humanizeCode($enrollment->status),
        ])->implode(' - ');
    }

    private static function paymentChannelLabel(?string $channel): string
    {
        return [
            'gcash' => 'GCash',
            'online_checkout' => 'Online Checkout',
            'synthetic_acceptance' => 'Acceptance Fixture',
        ][$channel] ?? self::humanizeCode($channel);
    }

    private static function paymentProviderLabel(?string $provider): string
    {
        return [
            'paymongo' => 'PayMongo',
            'synthetic_acceptance' => 'Acceptance Fixture',
        ][$provider] ?? self::humanizeCode($provider);
    }

    private static function humanizeCode(?string $code): string
    {
        return filled($code) ? Str::headline($code) : '-';
    }
}
