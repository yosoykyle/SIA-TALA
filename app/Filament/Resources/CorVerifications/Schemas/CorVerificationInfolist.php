<?php

namespace App\Filament\Resources\CorVerifications\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CorVerificationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('student_profile_id')
                    ->numeric(),
                TextEntry::make('term_id')
                    ->numeric(),
                TextEntry::make('enrollment_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('token'),
                TextEntry::make('status'),
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
