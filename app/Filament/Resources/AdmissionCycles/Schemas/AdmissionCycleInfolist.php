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
                        TextEntry::make('opens_at')->label('Opening')->dateTime(),
                        TextEntry::make('closes_at')->label('Public closing')->dateTime(),
                        TextEntry::make('correction_closes_at')->label('New-correction closing')->dateTime()->placeholder('Not set'),
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
                                    '%s — %s — %s → %s — %s — %s — %s',
                                    $event->occurred_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A'),
                                    str($event->event_type)->headline(),
                                    self::boundarySummary((array) $event->previous_values),
                                    self::boundarySummary((array) $event->new_values),
                                    $event->reason ?: 'No reason recorded',
                                    $event->authority_reference ?: 'No authority recorded',
                                    $event->actor?->email ?: 'Actor unavailable',
                                ))->implode("\n") ?: 'No publication or change event yet.')
                            ->listWithLineBreaks(),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }

    /** @param array<string, mixed> $values */
    private static function boundarySummary(array $values): string
    {
        return collect([
            'opening' => $values['opens_at'] ?? null,
            'public close' => $values['closes_at'] ?? null,
            'new-correction close' => $values['correction_closes_at'] ?? null,
            'state' => $values['state'] ?? null,
        ])->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value, string $label): string => "{$label}: {$value}")
            ->implode(', ') ?: 'No boundary value';
    }
}
