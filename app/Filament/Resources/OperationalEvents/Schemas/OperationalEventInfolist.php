<?php

namespace App\Filament\Resources\OperationalEvents\Schemas;

use App\Filament\Resources\OperationalEvents\Tables\OperationalEventsTable;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class OperationalEventInfolist
{
    /**
     * PII-safety posture (V1, matching TAL-92B's note): `payload`,
     * `diagnostics`, and `recipient_snapshot` are rendered as formatted JSON
     * as-is, with no new masking logic added beyond what the model already
     * casts. Secret substrings are the responsibility of upstream producers
     * never writing them into these columns; this view does not add or
     * remove protection.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('event_domain')
                    ->label('Domain')
                    ->badge(),
                TextEntry::make('integration')
                    ->placeholder('-'),
                TextEntry::make('channel')
                    ->placeholder('-'),
                TextEntry::make('direction')
                    ->placeholder('-'),
                TextEntry::make('event_type')
                    ->label('Event type'),
                TextEntry::make('event_version')
                    ->label('Event version')
                    ->placeholder('-'),
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => OperationalEventsTable::statusColors()[$state] ?? 'gray'),
                TextEntry::make('user.name')
                    ->label('Related User')
                    ->placeholder('System'),
                TextEntry::make('external_id')
                    ->label('External ID')
                    ->placeholder('-'),
                TextEntry::make('related_record_type')
                    ->label('Related record type')
                    ->placeholder('-'),
                TextEntry::make('related_record_id')
                    ->label('Related record ID')
                    ->placeholder('-'),
                TextEntry::make('source_run')
                    ->label('Source')
                    ->state(fn (OperationalEvent $record): ?string => self::sourceRunLabel($record))
                    ->url(fn (OperationalEvent $record): ?string => self::sourceRunUrl($record))
                    ->visible(fn (OperationalEvent $record): bool => self::sourceRun($record) instanceof ScheduleGenerationRun),
                TextEntry::make('occurred_at')
                    ->dateTime(),
                TextEntry::make('processed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('sent_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('failed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('diagnostics')
                    ->label('Diagnostics')
                    ->formatStateUsing(fn (mixed $state): string => self::formatJsonColumn($state))
                    ->columnSpanFull(),
                TextEntry::make('payload')
                    ->label('Payload')
                    ->formatStateUsing(fn (mixed $state): string => self::formatJsonColumn($state))
                    ->columnSpanFull(),
                TextEntry::make('recipient_snapshot')
                    ->label('Recipient snapshot')
                    ->formatStateUsing(fn (mixed $state): string => self::formatJsonColumn($state))
                    ->columnSpanFull(),
            ]);
    }

    private static function sourceRunLabel(OperationalEvent $event): ?string
    {
        $run = self::sourceRun($event);

        return $run instanceof ScheduleGenerationRun ? 'Schedule Run #'.$run->id : null;
    }

    private static function sourceRunUrl(OperationalEvent $event): ?string
    {
        $run = self::sourceRun($event);

        return $run instanceof ScheduleGenerationRun
            ? ScheduleGenerationRunResource::getUrl('view', ['record' => $run])
            : null;
    }

    private static function sourceRun(OperationalEvent $event): ?ScheduleGenerationRun
    {
        if ($event->related_record_type !== ScheduleGenerationRun::class
            || $event->integration !== OperationalEvent::IntegrationSchedulingSolver) {
            return null;
        }

        $run = $event->scheduleGenerationRun;

        return $run instanceof ScheduleGenerationRun && Gate::allows('view', $run)
            ? $run
            : null;
    }

    private static function formatJsonColumn(mixed $state): string
    {
        if ($state === null || $state === [] || $state === '') {
            return '-';
        }

        if (is_string($state)) {
            $decoded = json_decode($state, true);
            $state = $decoded ?? $state;
        }

        return is_array($state)
            ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : (string) $state;
    }
}
