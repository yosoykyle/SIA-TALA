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
                            ->label('Original Solver Score')
                            ->numeric()
                            ->placeholder('-'),
                        TextEntry::make('candidate_key')
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('Operations')
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
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ]),
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
                Section::make('Original Solver Result')
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
                    ]),
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
