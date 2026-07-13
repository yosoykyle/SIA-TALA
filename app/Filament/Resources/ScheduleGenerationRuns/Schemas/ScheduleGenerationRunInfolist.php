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
                        TextEntry::make('solver_result_status')
                            ->label('Solver Result')
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
                    ->columns(5),
            ]);
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
     * @return list<array<string, mixed>>
     */
    private static function findingRows(ScheduleGenerationRun $record): array
    {
        $findings = self::solverResult($record)['findings'] ?? null;

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
    private static function sourcePresentation(array $finding): array
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
