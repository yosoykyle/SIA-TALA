<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Schemas;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Filament\Resources\Terms\TermResource;
use App\Models\CalendarEvent;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Term;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ScheduleGenerationRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Generated Timetable Summary')
                    ->schema([
                        TextEntry::make('term.label')
                            ->label('Term'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => ScheduleGenerationRun::statusColors()[$state] ?? 'gray'),
                        TextEntry::make('requester.name')
                            ->label('Requested By')
                            ->placeholder('-'),
                    ])
                    ->columns(3),
                Section::make('Technical Run Details')
                    ->description('Diagnostic provenance for troubleshooting and defense evidence. These fields do not determine whether the timetable is official.')
                    ->schema([
                        TextEntry::make('dispatch_cycle')
                            ->label('Dispatch Cycle')
                            ->state(fn (ScheduleGenerationRun $record): int => $record->dispatchCycle())
                            ->numeric(),
                        TextEntry::make('solver_attempt_count')
                            ->label('Attempt Count')
                            ->state(fn (ScheduleGenerationRun $record): int => $record->solverAttemptCount())
                            ->numeric(),
                        TextEntry::make('latest_solver_outcome')
                            ->label('Latest Outcome')
                            ->state(fn (ScheduleGenerationRun $record): string => self::latestSolverOutcome($record))
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'Processed' => 'success',
                                'Failed' => 'danger',
                                'Pending' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('solver_retryable')
                            ->label('Retryable')
                            ->state(fn (ScheduleGenerationRun $record): string => ($record->finalSolverFailure()['retryable'] ?? false) ? 'Yes' : 'No')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Yes' ? 'warning' : 'gray'),
                        TextEntry::make('solver_failure_classification')
                            ->label('Failure Classification')
                            ->state(fn (ScheduleGenerationRun $record): string => Str::headline(
                                (string) ($record->finalSolverFailure()['classification'] ?? '-'),
                            ))
                            ->visible(fn (ScheduleGenerationRun $record): bool => $record->finalSolverFailure() !== []),
                        TextEntry::make('solver_failure_message')
                            ->label('Final Failure')
                            ->state(fn (ScheduleGenerationRun $record): string => (string) ($record->finalSolverFailure()['message'] ?? '-'))
                            ->visible(fn (ScheduleGenerationRun $record): bool => $record->finalSolverFailure() !== [])
                            ->columnSpanFull(),
                        TextEntry::make('solver_version')
                            ->label('Solver Version')
                            ->placeholder('-'),
                        TextEntry::make('model_version')
                            ->label('Model Version')
                            ->placeholder('-'),
                        TextEntry::make('runtime_ms')
                            ->label('Runtime (ms)')
                            ->numeric()
                            ->placeholder('-'),
                        TextEntry::make('objective_value')
                            ->label('Original Solver Score')
                            ->numeric()
                            ->placeholder('-'),
                        TextEntry::make('candidate_key')
                            ->label('Candidate Key')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Assignment Review')
                    ->description('Review assignment coverage, warnings, and conflicts before considering publication.')
                    ->schema([
                        TextEntry::make('candidate_row_total')
                            ->label('Candidate Assignments')
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
                Section::make('Publication Status')
                    ->description('A generated timetable remains a proposal until the Registrar explicitly publishes it.')
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
                Section::make('Current Validation')
                    ->schema([
                        TextEntry::make('current_validation_status')
                            ->label('Result')
                            ->state(fn (ScheduleGenerationRun $record): string => (string) (self::currentRevalidation($record)['status'] ?? '-'))
                            ->badge()
                            ->color(fn (string $state): string => $state === 'accepted' ? 'success' : ($state === 'blocked' ? 'danger' : 'gray')),
                        TextEntry::make('current_validation_context')
                            ->label('Validation Context')
                            ->state(fn (ScheduleGenerationRun $record): string => Str::headline((string) (self::currentRevalidation($record)['context'] ?? '-'))),
                        TextEntry::make('current_validation_time')
                            ->label('Validated At')
                            ->state(fn (ScheduleGenerationRun $record): mixed => self::currentRevalidation($record)['validated_at'] ?? null)
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('current_validation_assigned')
                            ->label('Assigned')
                            ->state(fn (ScheduleGenerationRun $record): int => (int) (self::currentSummary($record)['assigned_count'] ?? 0))
                            ->numeric(),
                        TextEntry::make('current_validation_unassigned')
                            ->label('Unassigned')
                            ->state(fn (ScheduleGenerationRun $record): int => (int) (self::currentSummary($record)['unassigned_count'] ?? 0))
                            ->numeric(),
                        TextEntry::make('current_validation_hard_violations')
                            ->label('Hard Violations')
                            ->state(fn (ScheduleGenerationRun $record): int => (int) (self::currentSummary($record)['hard_violation_count'] ?? 0))
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                        TextEntry::make('current_validation_warnings')
                            ->label('Warnings')
                            ->state(fn (ScheduleGenerationRun $record): int => (int) (self::currentSummary($record)['warning_count'] ?? 0))
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->visible(fn (ScheduleGenerationRun $record): bool => self::currentRevalidation($record) !== []),
                Section::make('Solution Quality')
                    ->description('These are optimization-quality measures for the generated timetable, not predictive accuracy or an accuracy score.')
                    ->schema([
                        TextEntry::make('solution_quality_status')
                            ->label('Solver Status')
                            ->state(fn (ScheduleGenerationRun $record): string => Str::headline(
                                (string) (self::solverResult($record)['solver_status'] ?? 'unknown'),
                            ))
                            ->badge()
                            ->color(fn (string $state): string => in_array($state, ['Optimal', 'Feasible'], true) ? 'success' : 'warning'),
                        TextEntry::make('solution_quality_meaning')
                            ->label('What This Status Means')
                            ->state(fn (ScheduleGenerationRun $record): string => self::solverStatusMeaning($record))
                            ->columnSpanFull(),
                        TextEntry::make('solution_quality_coverage')
                            ->label('Demand Coverage')
                            ->state(fn (ScheduleGenerationRun $record): string => self::demandCoverage($record)),
                        TextEntry::make('solution_quality_hard_conflicts')
                            ->label('Hard Conflicts')
                            ->state(fn (ScheduleGenerationRun $record): int => (int) (self::solverSummary($record)['hard_violation_count'] ?? 0))
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                        TextEntry::make('solution_quality_warnings')
                            ->label('Warnings')
                            ->state(fn (ScheduleGenerationRun $record): int => (int) (self::solverSummary($record)['warning_count'] ?? 0))
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                        TextEntry::make('solution_quality_objective')
                            ->label('Objective Value')
                            ->state(fn (ScheduleGenerationRun $record): string => self::qualityNumber(
                                self::solverStatistics($record)['objective_value'] ?? $record->objective_value,
                            )),
                        TextEntry::make('solution_quality_bound')
                            ->label('Best Bound')
                            ->state(fn (ScheduleGenerationRun $record): string => self::qualityNumber(
                                self::solverStatistics($record)['best_objective_bound'] ?? null,
                            )),
                        TextEntry::make('solution_quality_gap')
                            ->label('Relative Gap')
                            ->state(fn (ScheduleGenerationRun $record): string => self::relativeGap($record)),
                        TextEntry::make('solution_quality_runtime')
                            ->label('Runtime')
                            ->state(fn (ScheduleGenerationRun $record): string => self::runtime($record)),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->visible(fn (ScheduleGenerationRun $record): bool => self::solverResult($record) !== []),
                Section::make('Original Solver Result')
                    ->description('Original provider response retained for technical comparison with current Laravel validation.')
                    ->schema([
                        TextEntry::make('solver_result_status')
                            ->label('Result')
                            ->state(fn (ScheduleGenerationRun $record): string => (string) (self::solverResult($record)['solver_status'] ?? '-'))
                            ->badge()
                            ->color(fn (string $state): string => in_array($state, ['optimal', 'feasible'], true) ? 'success' : ($state === '-' ? 'gray' : 'danger')),
                        TextEntry::make('solver_result_assigned')
                            ->label('Assigned')
                            ->state(fn (ScheduleGenerationRun $record): int => (int) (self::solverSummary($record)['assigned_count'] ?? 0))
                            ->numeric(),
                        TextEntry::make('solver_result_unassigned')
                            ->label('Unassigned')
                            ->state(fn (ScheduleGenerationRun $record): int => (int) (self::solverSummary($record)['unassigned_count'] ?? 0))
                            ->numeric(),
                        TextEntry::make('solver_result_hard_violations')
                            ->label('Hard Violations')
                            ->state(fn (ScheduleGenerationRun $record): int => (int) (self::solverSummary($record)['hard_violation_count'] ?? 0))
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                        TextEntry::make('solver_result_warnings')
                            ->label('Warnings')
                            ->state(fn (ScheduleGenerationRun $record): int => (int) (self::solverSummary($record)['warning_count'] ?? 0))
                            ->badge()
                            ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 3,
                        'xl' => 5,
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Hard Constraint Checklist')
                    ->description('Each listed rule comes from this run\'s captured solver input. Passed means Laravel revalidated the complete candidate and found no violation for that rule.')
                    ->schema([
                        RepeatableEntry::make('hard_constraint_checklist')
                            ->label('Hard Constraint Checklist')
                            ->state(fn (ScheduleGenerationRun $record): array => self::hardConstraintRows($record))
                            ->table([
                                TableColumn::make('Rule'),
                                TableColumn::make('Result'),
                                TableColumn::make('Evidence'),
                            ])
                            ->schema([
                                TextEntry::make('label')
                                    ->wrap(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'Passed' => 'success',
                                        'Finding' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('evidence')
                                    ->wrap(),
                            ])
                            ->contained(false)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (ScheduleGenerationRun $record): bool => self::hardConstraintRows($record) !== []),
                Section::make('Soft Objective Evidence')
                    ->description('Soft objectives rank otherwise valid schedules. They are measured preferences, not pass/fail constraints or an accuracy score.')
                    ->schema([
                        RepeatableEntry::make('soft_objective_evidence')
                            ->label('Soft Objective Evidence')
                            ->state(fn (ScheduleGenerationRun $record): array => self::softObjectiveRows($record))
                            ->table([
                                TableColumn::make('Objective'),
                                TableColumn::make('Evidence status'),
                                TableColumn::make('Recorded result'),
                            ])
                            ->schema([
                                TextEntry::make('label')
                                    ->wrap(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (string $state): string => $state === 'Measured' ? 'success' : 'gray'),
                                TextEntry::make('evidence')
                                    ->wrap(),
                            ])
                            ->contained(false)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (ScheduleGenerationRun $record): bool => self::softObjectiveRows($record) !== []),
                Section::make('Validation Findings')
                    ->description(fn (ScheduleGenerationRun $record): string => self::currentRevalidation($record) !== []
                        ? 'Latest current-record revalidation findings.'
                        : 'Original solver-result validation findings.')
                    ->schema([
                        TextEntry::make('validation_result_empty')
                            ->label('Validation Findings')
                            ->state('No validation findings.')
                            ->visible(fn (ScheduleGenerationRun $record): bool => self::findingRows($record) === [])
                            ->columnSpanFull(),
                        RepeatableEntry::make('validation_findings')
                            ->label('Validation Findings')
                            ->state(fn (ScheduleGenerationRun $record): array => self::findingRows($record))
                            ->table([
                                TableColumn::make('Severity'),
                                TableColumn::make('Constraint'),
                                TableColumn::make('Finding'),
                                TableColumn::make('Source'),
                            ])
                            ->schema([
                                TextEntry::make('severity')
                                    ->badge()
                                    ->color(fn (string $state): string => $state === 'blocking' ? 'danger' : 'warning'),
                                TextEntry::make('constraint')
                                    ->formatStateUsing(fn (?string $state): string => Str::headline($state ?? '-')),
                                TextEntry::make('message')
                                    ->wrap(),
                                TextEntry::make('source_label')
                                    ->url(fn (Get $get): ?string => $get('source_url'))
                                    ->color(fn (Get $get): string => filled($get('source_url')) ? 'primary' : 'gray')
                                    ->wrap(),
                                TextEntry::make('source_url')
                                    ->hidden(),
                            ])
                            ->contained(false)
                            ->visible(fn (ScheduleGenerationRun $record): bool => self::findingRows($record) !== [])
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ]);
    }

    private static function latestSolverOutcome(ScheduleGenerationRun $record): string
    {
        $latestAttempt = $record->latestSolverAttempt();

        return $latestAttempt === null
            ? 'No Attempts'
            : Str::headline(strtolower($latestAttempt->status));
    }

    /**
     * @return array<string, mixed>
     */
    private static function solverResult(ScheduleGenerationRun $record): array
    {
        $diagnostics = $record->getAttribute('diagnostics');
        $result = is_array($diagnostics) ? $diagnostics['solver_result'] ?? null : null;

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function solverSummary(ScheduleGenerationRun $record): array
    {
        $summary = self::solverResult($record)['summary'] ?? null;

        return is_array($summary) ? $summary : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function solverStatistics(ScheduleGenerationRun $record): array
    {
        $statistics = self::solverResult($record)['solver_statistics'] ?? null;

        return is_array($statistics) ? $statistics : [];
    }

    private static function solverStatusMeaning(ScheduleGenerationRun $record): string
    {
        return match (strtolower((string) (self::solverResult($record)['solver_status'] ?? 'unknown'))) {
            'optimal' => 'The schedule satisfies the validated hard constraints, and the solver proved it was the best result for the tested model and objective.',
            'feasible' => 'The schedule satisfies the validated hard constraints, but the solver did not prove it was the best possible result within the time limit.',
            'infeasible' => 'The solver proved that no schedule satisfies all modeled hard constraints for this input.',
            default => 'The solver did not return enough evidence to classify the result as optimal, feasible, or infeasible.',
        };
    }

    private static function demandCoverage(ScheduleGenerationRun $record): string
    {
        $summary = self::solverSummary($record);
        $assigned = (int) ($summary['assigned_count'] ?? 0);
        $total = $assigned + (int) ($summary['unassigned_count'] ?? 0);

        return "{$assigned} of {$total} demands assigned";
    }

    private static function qualityNumber(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2) : 'Not reported';
    }

    private static function relativeGap(ScheduleGenerationRun $record): string
    {
        $gap = self::solverStatistics($record)['relative_optimality_gap'] ?? null;

        return is_numeric($gap) ? number_format((float) $gap * 100, 2).'%' : 'Not reported';
    }

    private static function runtime(ScheduleGenerationRun $record): string
    {
        $seconds = self::solverStatistics($record)['wall_time_seconds'] ?? null;

        if (! is_numeric($seconds) && is_numeric($record->runtime_ms)) {
            $seconds = (float) $record->runtime_ms / 1000;
        }

        return is_numeric($seconds) ? number_format((float) $seconds, 2).' seconds' : 'Not reported';
    }

    /**
     * @return array<string, mixed>
     */
    private static function currentRevalidation(ScheduleGenerationRun $record): array
    {
        $diagnostics = $record->getAttribute('diagnostics');
        $revalidation = is_array($diagnostics) ? $diagnostics['current_revalidation'] ?? null : null;

        return is_array($revalidation) ? $revalidation : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function currentSummary(ScheduleGenerationRun $record): array
    {
        $summary = self::currentRevalidation($record)['summary'] ?? null;

        return is_array($summary) ? $summary : [];
    }

    /**
     * @return list<array{label:string,status:string,evidence:string}>
     */
    private static function hardConstraintRows(ScheduleGenerationRun $record): array
    {
        $snapshot = self::inputSnapshot($record);
        $constraints = data_get($snapshot, 'constraint_profile.hard_constraints', $snapshot['hard_constraints'] ?? []);
        $findings = self::findingRows($record);
        $validation = self::currentRevalidation($record);
        $summary = $validation !== [] ? self::currentSummary($record) : self::solverSummary($record);
        $accepted = ($summary['status'] ?? null) === 'accepted'
            && (int) ($summary['hard_violation_count'] ?? 0) === 0;

        if (! is_array($constraints)) {
            return [];
        }

        return collect($constraints)
            ->filter(fn (mixed $constraint): bool => is_string($constraint) && $constraint !== '')
            ->unique()
            ->map(function (string $constraint) use ($findings, $accepted): array {
                $finding = collect($findings)->first(
                    fn (array $row): bool => ($row['constraint'] ?? null) === $constraint
                        && ($row['severity'] ?? null) === 'blocking',
                );

                return [
                    'label' => Str::headline($constraint),
                    'status' => is_array($finding) ? 'Finding' : ($accepted ? 'Passed' : 'Not proven'),
                    'evidence' => is_array($finding)
                        ? (string) ($finding['message'] ?? 'Laravel recorded a blocking finding for this rule.')
                        : ($accepted
                            ? 'Laravel accepted the complete candidate with no blocking finding for this rule.'
                            : 'This stored run does not contain an accepted Laravel validation result for this rule.'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{label:string,status:string,evidence:string}>
     */
    private static function softObjectiveRows(ScheduleGenerationRun $record): array
    {
        $snapshot = self::inputSnapshot($record);
        $weights = data_get($snapshot, 'constraint_profile.soft_weights', []);
        $constraints = data_get($snapshot, 'constraint_profile.soft_constraints', $snapshot['soft_constraints'] ?? []);
        $terms = data_get(self::solverResult($record), 'objective_details.terms', []);

        $weights = is_array($weights) ? $weights : [];
        $terms = is_array($terms) ? $terms : [];
        $constraints = is_array($constraints) && $constraints !== [] ? $constraints : array_keys($weights);

        return collect($constraints)
            ->filter(fn (mixed $constraint): bool => is_string($constraint) && $constraint !== '')
            ->unique()
            ->map(function (string $constraint) use ($weights, $terms): array {
                $term = $terms[$constraint] ?? null;

                if (is_array($term)) {
                    return [
                        'label' => Str::headline($constraint),
                        'status' => 'Measured',
                        'evidence' => sprintf(
                            'Raw %s; weight %s; weighted contribution %s.',
                            self::displayValue($term['raw'] ?? null),
                            self::displayValue($term['weight'] ?? $weights[$constraint] ?? null),
                            self::displayValue($term['weighted'] ?? null),
                        ),
                    ];
                }

                return [
                    'label' => Str::headline($constraint),
                    'status' => 'Configured',
                    'evidence' => 'Weight '.self::displayValue($weights[$constraint] ?? null).'; this stored run does not report a separate contribution.',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function inputSnapshot(ScheduleGenerationRun $record): array
    {
        $snapshot = $record->getAttribute('input_snapshot');

        return is_array($snapshot) ? $snapshot : [];
    }

    private static function displayValue(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'not reported';
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function findingRows(ScheduleGenerationRun $record): array
    {
        $latestValidation = self::currentRevalidation($record);
        $findings = $latestValidation !== []
            ? ($latestValidation['findings'] ?? null)
            : (self::solverResult($record)['findings'] ?? null);

        if (! is_array($findings)) {
            return [];
        }

        return collect($findings)
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->map(function (array $finding): array {
                $source = self::sourcePresentation($finding);

                return [
                    ...$finding,
                    'source_label' => $source['label'],
                    'source_url' => $source['url'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return array{label:string,url:string|null}
     */
    public static function sourcePresentation(array $finding): array
    {
        $type = (string) ($finding['source_type'] ?? 'source');
        $id = is_numeric($finding['source_id'] ?? null) ? (int) $finding['source_id'] : null;
        $field = filled($finding['source_field'] ?? null) ? (string) $finding['source_field'] : null;
        $label = Str::headline($type).($id !== null ? ' #'.$id : '').($field !== null ? ' / '.$field : '');

        if ($id === null) {
            return ['label' => $label, 'url' => null];
        }

        $url = match ($type) {
            'schedule_run' => self::viewUrl(ScheduleGenerationRun::query()->find($id), ScheduleGenerationRunResource::class),
            'scheduling_demand' => self::viewUrl(SchedulingDemand::query()->find($id), SchedulingDemandResource::class),
            'room' => self::viewUrl(Room::query()->find($id), RoomResource::class),
            'term' => self::viewUrl(Term::query()->find($id), TermResource::class),
            'faculty_qualification' => self::viewUrl(FacultyQualification::query()->find($id), FacultyQualificationResource::class),
            'faculty_term_load_override' => self::viewUrl(FacultyTermLoadOverride::query()->find($id), FacultyTermLoadOverrideResource::class),
            'calendar_event' => self::editUrl(CalendarEvent::query()->find($id), CalendarEventResource::class),
            default => null,
        };

        return ['label' => $label, 'url' => $url];
    }

    /**
     * @param  class-string  $resource
     */
    private static function viewUrl(?Model $record, string $resource): ?string
    {
        if (! $record instanceof Model || Gate::denies('view', $record)) {
            return null;
        }

        /** @var class-string<\Filament\Resources\Resource> $resource */
        return $resource::getUrl('view', ['record' => $record]);
    }

    /**
     * @param  class-string  $resource
     */
    private static function editUrl(?Model $record, string $resource): ?string
    {
        if (! $record instanceof Model || Gate::denies('update', $record)) {
            return null;
        }

        /** @var class-string<\Filament\Resources\Resource> $resource */
        return $resource::getUrl('edit', ['record' => $record]);
    }
}
