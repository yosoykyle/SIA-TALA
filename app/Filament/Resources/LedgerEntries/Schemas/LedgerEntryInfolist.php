<?php

namespace App\Filament\Resources\LedgerEntries\Schemas;

use App\Filament\Resources\AccountingAdjustments\AccountingAdjustmentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\AccountingAdjustment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LedgerEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account Activity')
                    ->description('An append-only balance event. Corrections add a linked adjustment or reversal instead of changing the original activity.')
                    ->schema([
                        TextEntry::make('studentProfile.student_number')
                            ->label('Student No.'),
                        TextEntry::make('studentProfile.user.name')
                            ->label('Student'),
                        TextEntry::make('term.label')
                            ->label('Term')
                            ->placeholder('Not term-specific'),
                        TextEntry::make('effect')
                            ->label('Balance Effect')
                            ->state(fn (LedgerEntry $record): string => $record->effectLabel())
                            ->badge(),
                        TextEntry::make('category')
                            ->label('Category')
                            ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                            ->badge(),
                        TextEntry::make('source')
                            ->label('Source')
                            ->state(fn (LedgerEntry $record): string => $record->sourceLabel())
                            ->url(fn (LedgerEntry $record): ?string => self::sourceUrl($record)),
                        TextEntry::make('description')
                            ->label('Reason / Description')
                            ->placeholder('No description recorded')
                            ->columnSpanFull(),
                        TextEntry::make('amount')
                            ->label('Recorded Amount')
                            ->money('PHP'),
                        TextEntry::make('state')
                            ->label('Posting State')
                            ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                            ->badge(),
                        TextEntry::make('payment.channel')
                            ->label('Payment Method')
                            ->formatStateUsing(fn (?string $state): string => filled($state)
                                ? str($state)->replace('_', ' ')->headline()->toString()
                                : 'Not applicable')
                            ->placeholder('Not applicable'),
                        TextEntry::make('posted_at')
                            ->label('Posted At')
                            ->dateTime()
                            ->placeholder('Not posted'),
                        TextEntry::make('poster.name')
                            ->label('Posted By')
                            ->placeholder('System'),
                    ])
                    ->columns(4),
                Section::make('Correction Trail')
                    ->schema([
                        TextEntry::make('correction_trace')
                            ->label('Relationship')
                            ->state(fn (LedgerEntry $record): string => match (true) {
                                $record->reverses_entry_id !== null => "This activity reverses activity #{$record->reverses_entry_id}.",
                                $record->adjusts_entry_id !== null => "This activity adjusts activity #{$record->adjusts_entry_id}.",
                                default => 'This is an original account activity with no correction link.',
                            }),
                    ])
                    ->visible(fn (LedgerEntry $record): bool => $record->reverses_entry_id !== null || $record->adjusts_entry_id !== null),
                Section::make('Technical Trace')
                    ->description('Identifiers retained for support and audit investigation.')
                    ->schema([
                        TextEntry::make('id')->label('Activity ID'),
                        TextEntry::make('enrollment.id')
                            ->label('Enrollment ID')
                            ->placeholder('Not linked'),
                        TextEntry::make('source_type')
                            ->label('Source Model')
                            ->placeholder('System posting'),
                        TextEntry::make('source_id')
                            ->label('Source Record ID')
                            ->placeholder('Not linked'),
                        TextEntry::make('created_at')
                            ->label('Recorded At')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Last Technical Update')
                            ->dateTime(),
                    ])
                    ->columns(3)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    private static function sourceUrl(LedgerEntry $entry): ?string
    {
        return match ($entry->source_type) {
            Payment::class => PaymentResource::getUrl('view', ['record' => $entry->source_id]),
            AccountingAdjustment::class => AccountingAdjustmentResource::getUrl('view', ['record' => $entry->source_id]),
            default => null,
        };
    }
}
