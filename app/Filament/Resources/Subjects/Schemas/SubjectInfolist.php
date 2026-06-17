<?php

namespace App\Filament\Resources\Subjects\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class SubjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label('Code'),
            TextEntry::make('description')->label('Subject Title'),
            TextEntry::make('units')->numeric(decimalPlaces: 2),
            TextEntry::make('lec_hours')->label('Legacy Lecture Hours')->numeric(decimalPlaces: 2)->placeholder('-'),
            TextEntry::make('department')->label('Education Level')->badge()->formatStateUsing(fn (?string $state): string => match ($state) {
                'college' => 'College',
                'shs' => 'Senior High School',
                default => str((string) $state)->headline()->toString(),
            }),
            TextEntry::make('subject_type')->label('Subject Type')->placeholder('-'),
            TextEntry::make('category')->placeholder('-'),
            TextEntry::make('created_at')->dateTime()->placeholder('-'),
            TextEntry::make('updated_at')->dateTime()->placeholder('-'),
        ]);
    }
}
