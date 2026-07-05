<?php

namespace App\Filament\Resources\Sections\Schemas;

use App\Models\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section as InfolistSection;
use Filament\Schemas\Schema;

class SectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                InfolistSection::make('Section Source Record')
                    ->schema([
                        TextEntry::make('termOffering.term.label')
                            ->label('Term'),
                        TextEntry::make('termOffering.curriculumEntry.courseSpecification.course.code')
                            ->label('Course Code')
                            ->placeholder('-'),
                        TextEntry::make('termOffering.curriculumEntry.courseSpecification.title')
                            ->label('Course Title')
                            ->placeholder('-'),
                        TextEntry::make('code')
                            ->label('Section Code'),
                        TextEntry::make('capacity')
                            ->numeric(),
                        TextEntry::make('state')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Section::stateOptions()[$state] ?? str($state)->headline()->toString())),
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('updated_at')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
