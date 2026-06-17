<?php

namespace App\Filament\Resources\Programs\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProgramInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label('Code'),
            TextEntry::make('name')->label('Program'),
            TextEntry::make('department')->label('Education Level')->badge()->formatStateUsing(fn (?string $state): string => match ($state) {
                'college' => 'College',
                'shs' => 'Senior High School',
                default => str((string) $state)->headline()->toString(),
            }),
            IconEntry::make('is_active')->label('Active')->boolean(),
            TextEntry::make('created_at')->dateTime()->placeholder('-'),
            TextEntry::make('updated_at')->dateTime()->placeholder('-'),
        ]);
    }
}
