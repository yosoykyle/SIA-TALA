<?php

namespace App\Filament\Resources\AdmissionCycles\Schemas;

use App\Actions\Admissions\AdmissionCycleReadinessService;
use App\Models\AdmissionCycle;
use App\Models\AdmissionCycleEvent;
use App\Models\Program;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdmissionCycleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Publication checks')
                    ->compact()
                    ->visible(fn (AdmissionCycle $record): bool => app(AdmissionCycleReadinessService::class)->for($record)['blockers'] !== [])
                    ->schema([
                        TextEntry::make('readiness')
                            ->label('Setup findings and next steps')
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
                Grid::make(['default' => 1, 'lg' => 2])->schema([
                    Section::make('Intake')
                        ->compact()
                        ->schema([
                            TextEntry::make('availability')->label('Applications')
                                ->state(fn (AdmissionCycle $record): string => match (true) {
                                    $record->state === AdmissionCycle::StateDraft => 'Draft — finish setup before publication',
                                    $record->state === AdmissionCycle::StateCancelled => 'Cancelled',
                                    $record->state !== AdmissionCycle::StatePublished || ! $record->opens_at || ! $record->closes_at => 'Application window unavailable — review the cycle dates',
                                    $record->opens_at->isFuture() => 'Scheduled',
                                    $record->closes_at <= now() => 'Closed — existing reviews continue',
                                    default => 'Open for new applications',
                                })->badge()
                                ->color(fn (AdmissionCycle $record): string => $record->state === AdmissionCycle::StatePublished && $record->opens_at?->isPast() && $record->closes_at?->isFuture() ? 'success' : 'gray'),
                            TextEntry::make('term.label')->label('Target term')->placeholder('Not selected'),
                            TextEntry::make('program_acceptance')
                                ->label('Programs and student types')
                                ->state(fn (AdmissionCycle $record): array => $record->programs
                                    ->map(function (Program $program): string {
                                        $pivot = $program->getRelation('pivot');
                                        $paths = collect([
                                            data_get($pivot, 'accepts_first_year') ? AdmissionCycle::studentTypeLabel(AdmissionCycle::PathFirstYear) : null,
                                            data_get($pivot, 'accepts_transferee') ? AdmissionCycle::studentTypeLabel(AdmissionCycle::PathTransferee) : null,
                                        ])->filter()->implode(', ');

                                        return "{$program->name}: ".($paths ?: 'No student type enabled');
                                    })->values()->all() ?: ['Choose accepting programs before publication.'])
                                ->listWithLineBreaks()->limitList(3)->expandableLimitedList(),
                            TextEntry::make('registrarOwner.email')->label('Responsible Registrar')->placeholder('Assign before publication'),
                        ]),
                    Section::make('Application dates')
                        ->description('All times are Asia/Manila.')
                        ->compact()
                        ->schema([
                            TextEntry::make('opens_at')->label('Applications open')->dateTime('M j, Y · g:i A')->timezone('Asia/Manila')->placeholder('Not set'),
                            TextEntry::make('closes_at')->label('New applications close')->dateTime('M j, Y · g:i A')->timezone('Asia/Manila')->placeholder('Not set')
                                ->helperText('Stops new starts and first submissions. Existing reviews continue.'),
                            TextEntry::make('correction_closes_at')->label('New correction requests end')->dateTime('M j, Y · g:i A')->timezone('Asia/Manila')->placeholder('Not set')
                                ->helperText('Active corrections remain available after this boundary.'),
                        ]),
                ])->columnSpanFull(),
                Section::make('Applicant guidance and source details')
                    ->compact()
                    ->schema([
                        TextEntry::make('applicant_instructions')->label('Instructions for applicants')->columnSpanFull(),
                        TextEntry::make('support_contact')->label('Admissions support contact'),
                        TextEntry::make('privacy_notice_reference')->label('Approved privacy notice reference'),
                        TextEntry::make('code')->label('Internal cycle code')->copyable(),
                        TextEntry::make('publication_summary')->label('Publication checks')
                            ->state(fn (AdmissionCycle $record): string => app(AdmissionCycleReadinessService::class)->for($record)['blockers'] === []
                                ? 'All setup checks passed' : 'Resolve the setup findings before publication'),
                    ])->columns(['default' => 1, 'md' => 2])->collapsible()->collapsed()->columnSpanFull(),
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
                    ->collapsible()->collapsed()
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
