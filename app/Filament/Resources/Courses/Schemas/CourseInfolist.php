<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\Course;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CourseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Course Identity')
                ->schema([
                    TextEntry::make('code')->label('Subject Code'),
                    TextEntry::make('state')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => Course::stateOptions()[$state] ?? str((string) $state)->headline()->toString()),
                    TextEntry::make('created_at')->dateTime()->placeholder('-'),
                    TextEntry::make('updated_at')->dateTime()->placeholder('-'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
