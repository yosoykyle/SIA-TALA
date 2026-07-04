<?php

namespace App\Filament\Resources\CurriculumVersions\Schemas;

use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CurriculumVersionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Curriculum Version')
                ->schema([
                    TextEntry::make('program.code')->label('Program'),
                    TextEntry::make('version_code')->label('Version Code'),
                    TextEntry::make('name')->label('Version Name'),
                    TextEntry::make('state')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => CurriculumVersion::stateOptions()[$state] ?? str((string) $state)->headline()->toString()),
                    TextEntry::make('approval_reference')->placeholder('-'),
                    TextEntry::make('approved_at')->dateTime()->placeholder('-'),
                    TextEntry::make('entry_summary')
                        ->label('Entries')
                        ->state(fn (CurriculumVersion $record): string => $record->entries()
                            ->with('courseSpecification.course')
                            ->orderBy('year_level')
                            ->orderBy('term_label')
                            ->orderBy('sequence')
                            ->get()
                            ->map(fn (CurriculumEntry $entry): string => collect([
                                $entry->year_level,
                                $entry->term_label,
                                $entry->sequence,
                                $entry->courseSpecification?->course?->code,
                                $entry->courseSpecification?->title,
                            ])->filter()->implode(' | '))
                            ->implode("\n"))
                        ->columnSpanFull()
                        ->placeholder('-'),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
