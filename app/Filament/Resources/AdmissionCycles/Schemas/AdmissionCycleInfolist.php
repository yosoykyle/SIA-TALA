<?php

namespace App\Filament\Resources\AdmissionCycles\Schemas;

use App\Actions\Admissions\AdmissionCycleReadinessService;
use App\Models\AdmissionCycle;
use App\Models\AdmissionCycleEvent;
use App\Models\Program;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdmissionCycleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Cycle and current authority')
                    ->schema([
                        TextEntry::make('code'),
                        TextEntry::make('label'),
                        TextEntry::make('state')->badge(),
                        TextEntry::make('term.label')->label('Target term'),
                        TextEntry::make('opens_at')->dateTime(),
                        TextEntry::make('closes_at')->dateTime(),
                        TextEntry::make('registrarOwner.email')->label('Registrar owner'),
                        TextEntry::make('support_contact'),
                        TextEntry::make('privacy_notice_reference'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Programs and paths')
                    ->schema([
                        TextEntry::make('program_acceptance')
                            ->label('Accepted scope')
                            ->state(fn (AdmissionCycle $record): string => $record->programs
                                ->map(function (Program $program): string {
                                    $pivot = $program->getRelation('pivot');
                                    $paths = collect([
                                        data_get($pivot, 'accepts_first_year') ? 'First year' : null,
                                        data_get($pivot, 'accepts_transferee') ? 'Transferee' : null,
                                    ])->filter()->implode(', ');

                                    return "{$program->name}: ".($paths ?: 'No path enabled');
                                })->implode("\n"))
                            ->listWithLineBreaks(),
                    ])
                    ->columnSpanFull(),
                Section::make('Failed-first publication readiness')
                    ->schema([
                        TextEntry::make('readiness')
                            ->label('Source → owner → reason → recovery')
                            ->state(function (AdmissionCycle $record): string {
                                if (! class_exists(AdmissionCycleReadinessService::class)) {
                                    return 'Readiness service is unavailable; publication remains blocked.';
                                }

                                $projection = app(AdmissionCycleReadinessService::class)->for($record);

                                return collect($projection['blockers'])
                                    ->map(fn (array $finding): string => implode(' → ', [
                                        $finding['source'],
                                        $finding['owner'],
                                        $finding['reason'],
                                        $finding['recovery'],
                                    ]))
                                    ->implode("\n") ?: 'All publication checks passed.';
                            })
                            ->listWithLineBreaks(),
                    ])
                    ->columnSpanFull(),
                Section::make('Publication and date-change history')
                    ->schema([
                        TextEntry::make('event_history')
                            ->label('Immutable events')
                            ->state(fn (AdmissionCycle $record): string => $record->events
                                ->sortByDesc('occurred_at')
                                ->map(fn (AdmissionCycleEvent $event): string => sprintf(
                                    '%s — %s — %s',
                                    $event->occurred_at->format('M j, Y g:i A'),
                                    str($event->event_type)->headline(),
                                    $event->reason ?: 'No reason recorded',
                                ))->implode("\n") ?: 'No publication or change event yet.')
                            ->listWithLineBreaks(),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }
}
