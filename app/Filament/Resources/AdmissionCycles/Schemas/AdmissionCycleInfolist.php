<?php

namespace App\Filament\Resources\AdmissionCycles\Schemas;

use App\Actions\Admissions\AdmissionCycleReadinessService;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AdmissionCycle;
use App\Models\AdmissionCycleEvent;
use App\Models\Program;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
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
                    ->description(fn (AdmissionCycle $record): string => trans_choice(
                        ':count item to fix before this cycle can be published.|:count items to fix before this cycle can be published.',
                        count(app(AdmissionCycleReadinessService::class)->for($record)['blockers']),
                    ))
                    ->visible(fn (AdmissionCycle $record): bool => app(AdmissionCycleReadinessService::class)->for($record)['blockers'] !== [])
                    ->schema([
                        View::make('filament.admin.admission-cycles.publication-checks')
                            ->viewData(fn (AdmissionCycle $record): array => [
                                'findings' => collect(app(AdmissionCycleReadinessService::class)->for($record)['blockers'])
                                    ->map(fn (array $finding): array => [...$finding, 'fix' => self::fixLink($finding, $record)])
                                    ->all(),
                            ]),
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
                            TextEntry::make('closes_at')->label('New applications close')->dateTime('M j, Y · g:i A')->timezone('Asia/Manila')->placeholder('Not set'),
                            TextEntry::make('correction_closes_at')->label('New correction requests end')->dateTime('M j, Y · g:i A')->timezone('Asia/Manila')->placeholder('Not set'),
                            TextEntry::make('requirements_due_at')->label('Physical papers due')->dateTime('M j, Y · g:i A')->timezone('Asia/Manila')->placeholder('Not set'),
                            TextEntry::make('payment_due_at')->label('Enrollment payment due')->dateTime('M j, Y · g:i A')->timezone('Asia/Manila')->placeholder('Not set'),
                            TextEntry::make('period_ends_at')->label('Admission period ends')->dateTime('M j, Y · g:i A')->timezone('Asia/Manila')->placeholder('Not set'),
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

    /**
     * Where the Registrar resolves a publication blocker, when it is fixable from TALA's cycle setup.
     *
     * @param  array{code: string}  $finding
     * @return array{label: string, url: string}|null
     */
    public static function fixLink(array $finding, AdmissionCycle $record): ?array
    {
        if (str_starts_with($finding['code'], 'requirement_set_')) {
            return ['label' => 'Open requirement sets', 'url' => AdmissionCycleResource::getUrl('view', ['record' => $record, 'relation' => '0'])];
        }

        $label = match ($finding['code']) {
            'target_term_and_dates' => 'Fix term and dates',
            'accepting_programs' => 'Choose accepting programs',
            'applicant_guidance' => 'Add applicant guidance',
            'registrar_owner' => 'Assign Registrar owner',
            default => null,
        };

        if ($label === null || $record->state !== AdmissionCycle::StateDraft || ! AdmissionCycleResource::canEdit($record)) {
            return null;
        }

        return ['label' => $label, 'url' => AdmissionCycleResource::getUrl('edit', ['record' => $record])];
    }
}
