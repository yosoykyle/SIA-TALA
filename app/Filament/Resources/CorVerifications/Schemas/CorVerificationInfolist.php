<?php

namespace App\Filament\Resources\CorVerifications\Schemas;

use App\Models\CorVerification;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CorVerificationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->placeholder('-'),
                TextEntry::make('studentProfile.user.name')
                    ->label('Student')
                    ->placeholder('-'),
                TextEntry::make('term.term_name')
                    ->label('Term')
                    ->placeholder('-'),
                TextEntry::make('enrollment_id')
                    ->label('Enrollment')
                    ->formatStateUsing(fn (?int $state, CorVerification $record): string => $record->enrollment?->displayLabel() ?? '-')
                    ->placeholder('-'),
                TextEntry::make('token'),
                TextEntry::make('status')
                    ->badge()
                    ->colors(CorVerification::statusColors()),
                TextEntry::make('issued_at')
                    ->dateTime(),
                TextEntry::make('expires_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('revoked_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('revocation_reason')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
