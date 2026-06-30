<?php

namespace App\Filament\Resources\GraduationReviewBatches\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GraduationReviewBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Batch')
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('academicYear.label')->label('Academic Year'),
                    TextEntry::make('term.label')->label('Term'),
                    TextEntry::make('state')->badge(),
                    TextEntry::make('creator.name')->label('Created By')->placeholder('System'),
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('closed_at')->dateTime()->placeholder('Open'),
                    KeyValueEntry::make('filter_summary')->label('Filter Summary')->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
