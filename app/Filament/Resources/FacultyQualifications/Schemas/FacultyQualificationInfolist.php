<?php

namespace App\Filament\Resources\FacultyQualifications\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FacultyQualificationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Approved Faculty Qualification')
                    ->schema([
                        TextEntry::make('faculty.name')->label('Faculty'),
                        TextEntry::make('course.code')->label('Course'),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                        TextEntry::make('recorder.name')->label('Recorded By')->placeholder('-'),
                        TextEntry::make('recorded_at')->dateTime(),
                        TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
