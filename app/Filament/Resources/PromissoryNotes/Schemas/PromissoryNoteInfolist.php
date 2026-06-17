<?php

namespace App\Filament\Resources\PromissoryNotes\Schemas;

use App\Models\PromissoryNote;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PromissoryNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('studentProfile.student_id')
                    ->label('Student ID'),
                TextEntry::make('studentProfile.user.name')
                    ->label('Student')
                    ->placeholder('-'),
                TextEntry::make('term.term_name')
                    ->label('Term')
                    ->placeholder('-'),
                TextEntry::make('enrollment.id')
                    ->label('Enrollment')
                    ->formatStateUsing(fn (?int $state, PromissoryNote $record): string => $record->enrollment === null
                        ? '-'
                        : PromissoryNote::enrollmentOptionLabel($record->enrollment)),
                TextEntry::make('ledgerEntry.id')
                    ->label('Ledger Entry')
                    ->formatStateUsing(fn (?int $state, PromissoryNote $record): string => $record->ledgerEntry === null
                        ? '-'
                        : PromissoryNote::ledgerEntryOptionLabel($record->ledgerEntry)),
                TextEntry::make('amount')
                    ->numeric(),
                TextEntry::make('due_date')
                    ->date(),
                TextEntry::make('status'),
                TextEntry::make('request_source')
                    ->label('Source')
                    ->placeholder('-'),
                TextEntry::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-'),
                TextEntry::make('requested_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('reason')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('approver.name')
                    ->label('Approved By')
                    ->placeholder('-'),
                TextEntry::make('approved_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('expired_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('rejector.name')
                    ->label('Rejected By')
                    ->placeholder('-'),
                TextEntry::make('rejected_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('rejection_reason')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('canceller.name')
                    ->label('Cancelled By')
                    ->placeholder('-'),
                TextEntry::make('cancelled_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('cancellation_reason')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('settler.name')
                    ->label('Settled By')
                    ->placeholder('-'),
                TextEntry::make('settled_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
