<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Schemas;

use App\Models\ScheduleGenerationRun;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScheduleGenerationRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Run')
                    ->schema([
                        TextEntry::make('term.label')
                            ->label('Term'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => ScheduleGenerationRun::statusColors()[$state] ?? 'gray'),
                        TextEntry::make('requester.name')
                            ->label('Requested By')
                            ->placeholder('-'),
                        TextEntry::make('solver_version')
                            ->placeholder('-'),
                        TextEntry::make('model_version')
                            ->placeholder('-'),
                        TextEntry::make('runtime_ms')
                            ->numeric()
                            ->placeholder('-'),
                        TextEntry::make('objective_value')
                            ->numeric()
                            ->placeholder('-'),
                        TextEntry::make('candidate_key')
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('Candidate Review')
                    ->schema([
                        TextEntry::make('candidate_row_total')
                            ->label('Candidate Rows')
                            ->state(fn (ScheduleGenerationRun $record): int => $record->candidateRows()->count())
                            ->numeric(),
                        TextEntry::make('candidate_row_conflicts')
                            ->label('Conflicts')
                            ->state(fn (ScheduleGenerationRun $record): int => $record->publicationSummary()['conflicts'])
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                        TextEntry::make('candidate_row_warnings')
                            ->label('Warnings')
                            ->state(fn (ScheduleGenerationRun $record): int => $record->publicationSummary()['warnings'])
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                    ])
                    ->columns(3),
                Section::make('Publication')
                    ->schema([
                        TextEntry::make('publication_version')
                            ->label('Version')
                            ->numeric()
                            ->placeholder('-'),
                        TextEntry::make('publisher.name')
                            ->label('Published By')
                            ->placeholder('-'),
                        TextEntry::make('published_at')
                            ->label('Published At')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('publication_note')
                            ->label('Publication Note')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Diagnostics')
                    ->schema([
                        KeyValueEntry::make('diagnostics')
                            ->label('Diagnostics')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
