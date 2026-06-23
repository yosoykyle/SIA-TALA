<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Schemas;

use App\Models\ScheduleDraftRow;
use App\Models\ScheduleGenerationRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ScheduleGenerationRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('term.term_name')
                    ->label('Term'),
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('requester.name')
                    ->label('Requested By')
                    ->placeholder('-'),
                TextEntry::make('generated_at')
                    ->dateTime(),
                TextEntry::make('committer.name')
                    ->label('Committed By')
                    ->placeholder('-'),
                TextEntry::make('committed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('publisher.name')
                    ->label('Published By')
                    ->placeholder('-'),
                TextEntry::make('published_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('publish_note')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('draft_row_total')
                    ->label('Draft Rows')
                    ->state(fn (ScheduleGenerationRun $record): int => $record->draftRows()->count())
                    ->numeric(),
                TextEntry::make('draft_row_conflicts')
                    ->label('Blocking Conflicts')
                    ->state(fn (ScheduleGenerationRun $record): int => $record->draftRows()->where('status', ScheduleDraftRow::StatusConflict)->count())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                TextEntry::make('draft_row_warnings')
                    ->label('Warnings')
                    ->state(fn (ScheduleGenerationRun $record): int => $record->draftRows()->where('status', ScheduleDraftRow::StatusWarning)->count())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextEntry::make('notes')
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

    private static function statusColor(string $status): string
    {
        return match ($status) {
            ScheduleGenerationRun::StatusGenerated => 'info',
            ScheduleGenerationRun::StatusDraft => 'warning',
            ScheduleGenerationRun::StatusUnderReview => 'gray',
            ScheduleGenerationRun::StatusBlocked => 'danger',
            ScheduleGenerationRun::StatusCommitted => 'success',
            ScheduleGenerationRun::StatusPublished => 'primary',
            default => 'gray',
        };
    }
}
