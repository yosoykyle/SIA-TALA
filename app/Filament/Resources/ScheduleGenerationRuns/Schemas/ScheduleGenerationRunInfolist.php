<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Schemas;

use App\Actions\Scheduling\ScheduleSolverRecoveryService;
use App\Actions\Scheduling\TimetableConflictDiagnosticService;
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
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Term;
use App\Models\User;
use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ScheduleGenerationRunInfolist
{
    /**
     * @var array<string, string>
     */
    private const HARD_CONSTRAINT_LABELS = [
        'assign_every_ready_scheduling_demand_once' => 'Every ready class placed once',
        'faculty_no_overlap' => 'Zero faculty overlaps',
        'room_no_overlap' => 'Zero room overlaps',
        'section_delivery_group_no_overlap' => 'Zero student-section overlaps',
        'student_cohort_no_overlap' => 'Zero student-section overlaps',
        'cohort_no_overlap' => 'Zero student-section overlaps',
        'respect_fixed_assignments' => 'Locked slots respected',
        'respect_calendar_blocks' => 'Calendar hours & breaks respected',
        'respect_room_capacity_type_and_features' => 'Room capacity & type matched',
        'room_suitability' => 'Room capacity & type matched',
        'respect_faculty_qualification_and_load' => 'Faculty qualifications & load caps respected',
    ];

    /**
     * @var array<string, string>
     */
    private const HARD_CONSTRAINT_PASSED_NOTES = [
        'assign_every_ready_scheduling_demand_once' => 'All ready class meetings are placed in the timetable.',
        'faculty_no_overlap' => 'No teacher is double-booked in overlapping time slots.',
        'room_no_overlap' => 'No physical room has overlapping class assignments.',
        'section_delivery_group_no_overlap' => 'No student section or cohort group has overlapping classes.',
        'student_cohort_no_overlap' => 'No student section or cohort group has overlapping classes.',
        'cohort_no_overlap' => 'No student section or cohort group has overlapping classes.',
        'respect_fixed_assignments' => 'All locked days, times, teachers, and rooms were kept intact.',
        'respect_calendar_blocks' => 'All classes fit inside active teaching hours and avoid breaks.',
        'respect_room_capacity_type_and_features' => 'Assigned rooms meet class size, room type, and equipment needs.',
        'room_suitability' => 'Assigned rooms meet class size, room type, and equipment needs.',
        'respect_faculty_qualification_and_load' => 'Assigned teachers hold active subject approval within term load limits.',
    ];

    /**
     * @var array<string, string>
     */
    private const SOFT_OBJECTIVE_LABELS = [
        'cohort_mode_switches' => 'Cohort mode switches',
        'cohort_idle_time' => 'Cohort idle time',
        'faculty_load_imbalance' => 'Faculty load balance',
        'faculty_idle_time' => 'Faculty idle time',
        'room_seat_waste' => 'Unused room seats',
        'stable_earlier_placement' => 'Stability preference',
    ];

    public static function reviewSummary(ScheduleGenerationRun $record): View
    {
        return View::make('filament.pages.partials.timetable-run-summary')
            ->columnSpanFull();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.partials.timetable-run-summary')
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function reportData(ScheduleGenerationRun $record, ?int $contextTermId = null): array
    {
        $record->loadMissing(['term.academicYear', 'requester', 'publisher', 'candidateReviewer']);

        $isActiveRun = in_array($record->status, [
            ScheduleGenerationRun::StatusQueued,
            ScheduleGenerationRun::StatusDispatching,
        ], true);

        $wasPublished = $record->isPublished() || $record->published_at !== null;
        $publishedVersion = $wasPublished
            ? $record->timetableVersions()->where('term_id', $record->term_id)->latest('version')->first()
            : null;

        $evidence = $record->reviewEvidence();
        $publicationSummary = $record->publicationSummary();
        $assignmentCount = (int) $publicationSummary['assignments'];
        $warningCount = (int) $publicationSummary['warnings'];

        $currentRevalidation = self::currentRevalidation($record);
        $currentSummary = self::currentSummary($record);
        $solverSummary = self::solverSummary($record);
        $activeSummary = $currentRevalidation !== [] ? $currentSummary : $solverSummary;

        $requiredCount = self::requiredMeetingCount($record, $activeSummary, $assignmentCount);
        $meetingsMetric = $requiredCount > 0
            ? "{$assignmentCount} / {$requiredCount}"
            : (string) $assignmentCount;

        $hardConflicts = self::hardConflictCount($activeSummary, $publicationSummary, $assignmentCount);
        $runtimeSeconds = self::runtimeSeconds($record);
        $runtimeShort = $runtimeSeconds !== null ? self::formatShortSeconds($runtimeSeconds) : '—';
        $runtimeLong = $runtimeSeconds !== null ? number_format($runtimeSeconds, 2).' seconds' : '—';

        $statusMetric = self::statusMetric($record, $wasPublished, $publishedVersion);
        $validationPresentation = self::validationPresentation($record, $evidence, $currentRevalidation);
        $outcomeColor = match ($evidence['outcome']) {
            'Optimal', 'Feasible' => 'success',
            'Infeasible', 'ModelInvalid', 'TechnicalFailure' => 'danger',
            'Local adjustment' => 'info',
            default => 'warning',
        };

        $validatedAtRaw = $currentRevalidation['validated_at'] ?? null;
        $validatedAtFormatted = filled($validatedAtRaw)
            ? CarbonImmutable::parse((string) $validatedAtRaw)->timezone('Asia/Manila')->format('M j, Y, g:i A')
            : DisplayDateTime::format($record->updated_at ?? $record->created_at);

        $conflictDiagnosis = [
            'has_conflicts' => false,
            'summary' => '',
            'items' => [],
        ];

        if (! $wasPublished
            && ! $isActiveRun
            && (! ($evidence['validated'] ?? false)
                || $hardConflicts > 0
                || $record->candidate_state === 'Stale'
                || in_array($record->status, [
                    ScheduleGenerationRun::StatusBlocked,
                    ScheduleGenerationRun::StatusFailed,
                ], true))) {
            $conflictDiagnosis = app(TimetableConflictDiagnosticService::class)->diagnose($record, $record->term);
        }

        $relativeGap = self::relativeGap($record);
        $findingRows = self::findingRows($record, $contextTermId ?? $record->term_id, $conflictDiagnosis);
        $hardConstraintRows = self::hardConstraintRows($record, $findingRows);
        $qualityRows = self::qualityMeasureRows($record);
        $hasQualitySection = $qualityRows !== [] || self::solverStatistics($record) !== [] || is_numeric($record->objective_value);

        $failure = $record->finalSolverFailure();
        $showFailureDiagnostics = $record->status === ScheduleGenerationRun::StatusFailed || $failure !== [];

        $recoverySourceId = data_get($record->diagnostics, 'technical_recovery.source_run_id');
        $recovery = filled($recoverySourceId) ? [
            'source_run_id' => (int) $recoverySourceId,
            'source_url' => ScheduleGenerationRunResource::getUrl('view', [
                'record' => (int) $recoverySourceId,
                'termId' => $record->term_id,
            ]),
            'actor' => ($record->requester?->name ?? 'Staff member').(filled(data_get($record->diagnostics, 'technical_recovery.requested_by'))
                ? ' (#'.data_get($record->diagnostics, 'technical_recovery.requested_by').')'
                : ''),
            'requested_at' => filled(data_get($record->diagnostics, 'technical_recovery.requested_at'))
                ? CarbonImmutable::parse((string) data_get($record->diagnostics, 'technical_recovery.requested_at'))->timezone('Asia/Manila')->format('M j, Y, g:i A')
                : null,
            'explanation' => (string) data_get($record->diagnostics, 'technical_recovery.correction_explanation', ''),
        ] : null;

        $blockerMessage = $record->status === ScheduleGenerationRun::StatusBlocked
            ? (($conflictDiagnosis['has_conflicts'] ?? false) && ($conflictDiagnosis['summary'] ?? '') !== ''
                ? (string) $conflictDiagnosis['summary']
                : ($failure['message'] ?? data_get($record->diagnostics, 'solver_result.summary.message', 'Review the validation findings below and correct the linked source records before generating again.')))
            : null;

        $termParts = array_filter([
            $record->term?->label,
            $record->term?->academicYear?->label,
        ]);

        $actor = auth()->user();
        $isSystemAdmin = $actor instanceof User && $actor->hasRole(User::StaffRoleSystemSuperAdmin);

        return [
            'run' => $record,
            'isActiveRun' => $isActiveRun,
            'wasPublished' => $wasPublished,
            'publishedVersion' => $publishedVersion,
            'statusMetric' => $statusMetric,
            'meetingsMetric' => $meetingsMetric,
            'meetingsSubnote' => $assignmentCount === 0
                ? 'No placements recorded'
                : ($warningCount > 0 ? $warningCount.' advisory '.Str::plural('warning', $warningCount) : 'All placed meetings recorded'),
            'hardConflicts' => $hardConflicts,
            'hardConflictsTone' => $hardConflicts > 0 ? 'danger' : ($evidence['validated'] ? 'success' : 'neutral'),
            'hardConflictsSubnote' => $hardConflicts > 0
                ? $hardConflicts.' blocking '.Str::plural('issue', $hardConflicts).' flagged'
                : ($evidence['validated'] ? 'Zero rule violations' : $evidence['conflicts']),
            'runtimeShort' => $runtimeShort,
            'runtimeLong' => $runtimeLong,
            'runtimeSubnote' => $evidence['outcome'],
            'publisherName' => $record->publisher?->name ?? 'Registrar',
            'publishedAtFormatted' => $wasPublished
                ? DisplayDateTime::format($publishedVersion?->published_at ?? $record->published_at)
                : null,
            'publishedVersionNumber' => $publishedVersion?->version ?? $record->publication_version,
            'publicationNote' => filled($record->publication_note) ? (string) $record->publication_note : null,
            'validatedAtFormatted' => $validatedAtFormatted,
            'validationBadge' => $validationPresentation['badge'],
            'validationColor' => $validationPresentation['color'],
            'validationCoverage' => $evidence['coverage'],
            'outcomeBadge' => $evidence['outcome'],
            'outcomeColor' => $outcomeColor,
            'outcomeMeaning' => $evidence['meaning'],
            'relativeGap' => $relativeGap,
            'hardConstraintRows' => $hardConstraintRows,
            'qualityRows' => $qualityRows,
            'hasQualitySection' => $hasQualitySection,
            'findingRows' => $findingRows,
            'conflictDiagnosis' => $conflictDiagnosis,
            'showFailureDiagnostics' => $showFailureDiagnostics,
            'failureInfo' => [
                'classification' => filled($failure['classification'] ?? null) ? Str::headline((string) $failure['classification']) : 'Service failure',
                'message' => filled($failure['message'] ?? null) ? (string) $failure['message'] : 'The solver request did not complete with a valid result.',
                'retryable' => ($failure['retryable'] ?? false) ? 'Yes' : 'No',
                'attemptCount' => $record->solverAttemptCount(),
                'dispatchCycle' => $record->dispatchCycle(),
                'guidance' => self::nextStep($record),
            ],
            'blockerMessage' => is_string($blockerMessage) && $blockerMessage !== '' ? $blockerMessage : null,
            'recovery' => $recovery,
            'runReference' => [
                'term' => $termParts !== [] ? implode(' · ', array_unique($termParts)) : '—',
                'requestedBy' => $record->requester?->name ?? 'System',
                'attempt' => '#'.$record->id,
                'runtime' => $runtimeLong,
            ],
            'isSystemAdmin' => $isSystemAdmin,
            'adminMetadata' => $isSystemAdmin ? [
                'solverVersion' => $record->solver_version ?: '—',
                'modelVersion' => $record->model_version ?: (string) data_get(self::inputSnapshot($record), 'contract_version', '—'),
                'candidateKey' => $record->candidate_key ?: '—',
            ] : null,
        ];
    }

    public static function humanConstraintLabel(?string $constraint): string
    {
        if (! filled($constraint)) {
            return '—';
        }

        return self::HARD_CONSTRAINT_LABELS[$constraint]
            ?? self::SOFT_OBJECTIVE_LABELS[$constraint]
            ?? Str::headline($constraint);
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array<string, Collection<int, Model>>  $preloaded
     * @param  array{demands?:array<int,string>,faculty?:array<int,string>,rooms?:array<int,string>,cohorts?:array<int,string>}  $entityLabels
     * @return array{label:string,url:string|null}
     */
    public static function sourcePresentation(array $finding, ?int $termId = null, array $preloaded = [], array $entityLabels = []): array
    {
        $type = (string) ($finding['source_type'] ?? 'source');
        $id = is_numeric($finding['source_id'] ?? null) ? (int) $finding['source_id'] : null;
        $field = filled($finding['source_field'] ?? null) ? (string) $finding['source_field'] : null;

        if ($id === null) {
            return ['label' => self::humanSourceTypeFallback($type, $field), 'url' => null];
        }

        $context = in_array($type, ['scheduling_demand', 'room', 'faculty_qualification'], true) && $termId !== null
            ? ['termId' => $termId]
            : [];

        $resolvedRecord = match ($type) {
            'schedule_run' => $preloaded['schedule_run'][$id] ?? ScheduleGenerationRun::query()->find($id),
            'scheduling_demand' => $preloaded['scheduling_demand'][$id] ?? SchedulingDemand::query()
                ->with([
                    'courseComponent.courseSpecification.course:id,code',
                    'sectionDeliveryGroup.section:id,class_reference,code',
                    'sectionDeliveryGroup.section.cohorts:id,reference',
                    'termOffering.curriculumEntry.courseSpecification.course:id,code',
                ])
                ->find($id),
            'room' => $preloaded['room'][$id] ?? Room::query()->find($id),
            'term' => $preloaded['term'][$id] ?? Term::query()->find($id),
            'faculty_qualification' => $preloaded['faculty_qualification'][$id] ?? FacultyQualification::query()
                ->with(['faculty:id,name', 'course:id,code'])
                ->find($id),
            'faculty_term_load_override' => $preloaded['faculty_term_load_override'][$id] ?? FacultyTermLoadOverride::query()
                ->with('faculty:id,name')
                ->find($id),
            'calendar_event' => $preloaded['calendar_event'][$id] ?? CalendarEvent::query()->find($id),
            default => null,
        };

        $label = self::humanSourceLabel($type, $id, $resolvedRecord, $entityLabels, $field);

        $url = match ($type) {
            'schedule_run' => self::viewUrl($resolvedRecord, ScheduleGenerationRunResource::class),
            'scheduling_demand' => self::viewUrl($resolvedRecord, SchedulingDemandResource::class, $context),
            'room' => self::viewUrl($resolvedRecord, RoomResource::class, $context),
            'term' => self::viewUrl($resolvedRecord, TermResource::class),
            'faculty_qualification' => self::viewUrl($resolvedRecord, FacultyQualificationResource::class, $context),
            'faculty_term_load_override' => self::viewUrl($resolvedRecord, FacultyTermLoadOverrideResource::class),
            'calendar_event' => self::editUrl($resolvedRecord, CalendarEventResource::class, $termId !== null ? ['termId' => $termId] : []),
            default => null,
        };

        return ['label' => $label, 'url' => $url];
    }

    /**
     * @param  array{demands?:array<int,string>,faculty?:array<int,string>,rooms?:array<int,string>,cohorts?:array<int,string>}  $entityLabels
     */
    private static function humanSourceLabel(
        string $type,
        int $id,
        ?Model $record,
        array $entityLabels,
        ?string $field = null,
    ): string {
        if ($type === 'scheduling_demand') {
            if (! empty($entityLabels['demands'][$id])) {
                return $entityLabels['demands'][$id];
            }
            if ($record instanceof SchedulingDemand) {
                $courseCode = trim((string) (
                    $record->courseComponent?->courseSpecification?->course?->code
                    ?: $record->termOffering?->curriculumEntry?->courseSpecification?->course?->code
                    ?: data_get($record->source_snapshot, 'course_code', '')
                ));
                $section = $record->sectionDeliveryGroup?->section;
                $sectionRef = $section && $section->relationLoaded('cohorts') && $section->cohorts->isNotEmpty()
                    ? $section->cohorts->pluck('reference')->filter()->unique()->implode(', ')
                    : trim((string) ($section?->class_reference ?: $section?->code ?: ''));
                $comp = match (strtoupper((string) ($record->courseComponent?->component_type ?? ''))) {
                    'LECTURE' => 'Lecture',
                    'LABORATORY' => 'Lab',
                    default => '',
                };
                $base = implode(' · ', array_filter([$courseCode, $sectionRef]));
                if ($base !== '') {
                    return $comp !== '' ? sprintf('%s (%s)', $base, $comp) : $base;
                }
            }

            return 'Scheduled class';
        }

        if ($type === 'room') {
            if (! empty($entityLabels['rooms'][$id])) {
                return 'Room '.$entityLabels['rooms'][$id];
            }
            if ($record instanceof Room) {
                $code = trim((string) $record->code);
                $name = trim((string) $record->name);
                if ($code !== '') {
                    return $name !== '' && strcasecmp($code, $name) !== 0 ? "Room {$code} · {$name}" : "Room {$code}";
                }
            }

            return 'Campus room';
        }

        if ($type === 'faculty_qualification') {
            if ($record instanceof FacultyQualification) {
                $parts = array_filter([
                    $record->faculty?->name,
                    $record->course?->code,
                ]);
                if ($parts !== []) {
                    return implode(' · ', $parts);
                }
            }

            return 'Faculty qualification';
        }

        if ($type === 'faculty_term_load_override') {
            if ($record instanceof FacultyTermLoadOverride && $record->faculty?->name) {
                return $record->faculty->name.' · Load override';
            }

            return 'Faculty load override';
        }

        if ($type === 'term') {
            return $record instanceof Term && filled($record->label) ? (string) $record->label : 'Term settings';
        }

        if ($type === 'calendar_event') {
            if ($record instanceof CalendarEvent && filled($record->event_type)) {
                return Str::headline((string) $record->event_type);
            }

            return 'Calendar block';
        }

        if ($type === 'schedule_run') {
            return 'Attempt #'.$id;
        }

        return self::humanSourceTypeFallback($type, $field);
    }

    private static function humanSourceTypeFallback(string $type, ?string $field = null): string
    {
        $base = match ($type) {
            'scheduling_demand' => 'Scheduled class',
            'room' => 'Campus room',
            'faculty_qualification' => 'Faculty qualification',
            'faculty_term_load_override' => 'Faculty load override',
            'calendar_event' => 'Calendar block',
            'schedule_run' => 'Timetable attempt',
            default => Str::headline($type),
        };

        return $field !== null && ! str_contains($field, '_') && $type === 'source'
            ? $base.' · '.Str::headline($field)
            : $base;
    }

    /**
     * @return array{label:string,subnote:string,tone:string}
     */
    private static function statusMetric(
        ScheduleGenerationRun $record,
        bool $wasPublished,
        ?PublishedTimetableVersion $publishedVersion,
    ): array {
        $versionNumber = $publishedVersion?->version ?? $record->publication_version;
        $versionSuffix = $versionNumber !== null ? ' v'.$versionNumber : '';

        if ($record->isPublished()) {
            $isSupersededVersion = $publishedVersion instanceof PublishedTimetableVersion
                && $publishedVersion->state !== 'Published';

            return [
                'label' => $isSupersededVersion ? 'Replaced' : 'Published'.$versionSuffix,
                'subnote' => $isSupersededVersion ? 'Historical version'.$versionSuffix : 'Official timetable',
                'tone' => $isSupersededVersion ? 'neutral' : 'success',
            ];
        }

        if ($record->status === ScheduleGenerationRun::StatusSuperseded) {
            return [
                'label' => 'Replaced',
                'subnote' => $wasPublished ? 'Formerly Published'.$versionSuffix : 'Superseded by newer attempt',
                'tone' => 'neutral',
            ];
        }

        if ($record->status === ScheduleGenerationRun::StatusUnderReview) {
            return match ($record->candidate_state) {
                'Accepted' => [
                    'label' => 'Accepted draft',
                    'subnote' => 'Ready to publish in Timetable',
                    'tone' => 'success',
                ],
                'Rejected' => [
                    'label' => 'Rejected',
                    'subnote' => 'Cannot be published',
                    'tone' => 'danger',
                ],
                'Stale' => [
                    'label' => 'Stale draft',
                    'subnote' => 'Source records changed',
                    'tone' => 'warning',
                ],
                default => [
                    'label' => 'Draft',
                    'subnote' => 'Under review in Timetable',
                    'tone' => 'neutral',
                ],
            };
        }

        if ($record->status === ScheduleGenerationRun::StatusBlocked) {
            return [
                'label' => 'Blocked',
                'subnote' => 'Needs source correction',
                'tone' => 'danger',
            ];
        }

        if ($record->status === ScheduleGenerationRun::StatusFailed) {
            return [
                'label' => 'Failed',
                'subnote' => 'No trusted schedule produced',
                'tone' => 'danger',
            ];
        }

        return [
            'label' => ScheduleGenerationRun::statusOptions()[$record->status] ?? Str::headline((string) $record->status),
            'subnote' => 'Processing solver request',
            'tone' => 'warning',
        ];
    }

    /**
     * @param  array{outcome:string,meaning:string,validated:bool,coverage:string,conflicts:string}  $evidence
     * @param  array<string, mixed>  $currentRevalidation
     * @return array{badge:string,color:string}
     */
    private static function validationPresentation(
        ScheduleGenerationRun $record,
        array $evidence,
        array $currentRevalidation,
    ): array {
        if ($evidence['validated']) {
            return ['badge' => 'Verified', 'color' => 'success'];
        }

        $revalidationStatus = (string) ($currentRevalidation['status'] ?? '');

        if ($revalidationStatus === 'blocked' || $record->status === ScheduleGenerationRun::StatusBlocked) {
            return ['badge' => 'Blocked', 'color' => 'danger'];
        }

        if ($record->status === ScheduleGenerationRun::StatusFailed) {
            return ['badge' => 'Failed', 'color' => 'danger'];
        }

        if (in_array($record->candidate_state, ['Stale', 'Rejected', 'Superseded'], true)) {
            return ['badge' => Str::headline((string) $record->candidate_state), 'color' => 'warning'];
        }

        return ['badge' => 'Unverified', 'color' => 'warning'];
    }

    /**
     * @param  array<string, mixed>  $activeSummary
     */
    private static function requiredMeetingCount(
        ScheduleGenerationRun $record,
        array $activeSummary,
        int $assignmentCount,
    ): int {
        if (is_numeric($activeSummary['required_meeting_count'] ?? null)) {
            return (int) $activeSummary['required_meeting_count'];
        }

        $demands = data_get(self::inputSnapshot($record), 'scheduling_demands', []);
        if (is_array($demands) && $demands !== []) {
            $sum = collect($demands)->sum(fn (mixed $item): int => is_array($item) ? max(1, (int) ($item['meeting_count'] ?? 1)) : 1);

            if ($sum > 0) {
                return (int) $sum;
            }
        }

        return $assignmentCount;
    }

    /**
     * @param  array<string, mixed>  $activeSummary
     * @param  array{assignments:int,warnings:int,conflicts:int}  $publicationSummary
     */
    private static function hardConflictCount(
        array $activeSummary,
        array $publicationSummary,
        int $assignmentCount,
    ): int {
        if (is_numeric($activeSummary['hard_violation_count'] ?? null)) {
            return max((int) $activeSummary['hard_violation_count'], $publicationSummary['conflicts']);
        }

        return $assignmentCount > 0 ? $publicationSummary['conflicts'] : 0;
    }

    private static function nextStep(ScheduleGenerationRun $record): string
    {
        if (app(ScheduleSolverRecoveryService::class)->canRequestRecovery($record) && ! $record->canRetrySolver()) {
            return 'After the System Administrator confirms the service fix, a Registrar can choose Try again after a service fix and record what was corrected. A new attempt checks current planning readiness; this failed attempt stays in history.';
        }

        return match ($record->status) {
            ScheduleGenerationRun::StatusBlocked => 'Review the validation findings and correct the linked source records. Return to Timetable to check readiness and prepare the next request.',
            ScheduleGenerationRun::StatusFailed => 'Review the failure in Technical diagnostics. Fix any class or resource issues in Classes & sections or Faculty & rooms, or contact the System Administrator for service failures, then return to Timetable to try again.',
            default => 'Return to Timetable to review or manage the schedule for this term.',
        };
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

    private static function relativeGap(ScheduleGenerationRun $record): ?string
    {
        $gap = self::solverStatistics($record)['relative_optimality_gap'] ?? null;

        if (! is_numeric($gap)) {
            return null;
        }

        return number_format((float) $gap * 100, 2).'%';
    }

    private static function runtimeSeconds(ScheduleGenerationRun $record): ?float
    {
        $seconds = self::solverStatistics($record)['wall_time_seconds'] ?? null;

        if (! is_numeric($seconds) && is_numeric($record->runtime_ms)) {
            $seconds = (float) $record->runtime_ms / 1000;
        }

        return is_numeric($seconds) ? (float) $seconds : null;
    }

    private static function formatShortSeconds(float $seconds): string
    {
        $roundedTwo = round($seconds, 2);
        $decimals = abs($roundedTwo - round($roundedTwo, 1)) > 0.001 ? 2 : 1;

        return number_format($roundedTwo, $decimals).'s';
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
     * @param  list<array<string, mixed>>|null  $precomputedFindings
     * @return list<array{label:string,status:string,color:string,notes:string}>
     */
    private static function hardConstraintRows(ScheduleGenerationRun $record, ?array $precomputedFindings = null): array
    {
        $snapshot = self::inputSnapshot($record);
        $constraints = data_get($snapshot, 'constraint_profile.hard_constraints', $snapshot['hard_constraints'] ?? []);
        $findings = $precomputedFindings ?? self::findingRows($record, $record->term_id);
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

                if (is_array($finding)) {
                    return [
                        'label' => self::humanConstraintLabel($constraint),
                        'status' => 'Flagged',
                        'color' => 'danger',
                        'notes' => (string) ($finding['message'] ?? 'A blocking conflict was recorded for this rule.'),
                    ];
                }

                if ($accepted) {
                    return [
                        'label' => self::humanConstraintLabel($constraint),
                        'status' => 'Passed',
                        'color' => 'success',
                        'notes' => self::HARD_CONSTRAINT_PASSED_NOTES[$constraint]
                            ?? 'Verified across the complete schedule with zero violations.',
                    ];
                }

                return [
                    'label' => self::humanConstraintLabel($constraint),
                    'status' => 'Unverified',
                    'color' => 'gray',
                    'notes' => 'Validation did not complete for this attempt.',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{priority:int|null,label:string,value:string}>
     */
    private static function qualityMeasureRows(ScheduleGenerationRun $record): array
    {
        $snapshot = self::inputSnapshot($record);
        $hierarchy = data_get($snapshot, 'constraint_profile.objective_hierarchy', []);
        $solverValues = data_get(self::solverResult($record), 'objective_details.values', []);
        $storedMeasures = is_array($record->quality_measures) ? $record->quality_measures : [];

        if (is_array($hierarchy) && $hierarchy !== []) {
            $rows = collect($hierarchy)
                ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
                ->values()
                ->map(function (string $key, int $index) use ($storedMeasures, $solverValues): ?array {
                    $raw = $storedMeasures[$key] ?? (is_array($solverValues) ? ($solverValues[$key] ?? null) : null);

                    if (! is_numeric($raw)) {
                        return null;
                    }

                    return [
                        'priority' => $index + 1,
                        'label' => self::SOFT_OBJECTIVE_LABELS[$key] ?? Str::headline($key),
                        'value' => self::formatQualityMeasure($key, (float) $raw),
                    ];
                })
                ->filter()
                ->values()
                ->all();

            if ($rows !== []) {
                return $rows;
            }
        }

        if ($storedMeasures !== []) {
            $rows = collect(array_keys(self::SOFT_OBJECTIVE_LABELS))
                ->values()
                ->map(function (string $key, int $index) use ($storedMeasures): ?array {
                    $raw = $storedMeasures[$key] ?? null;

                    if (! is_numeric($raw)) {
                        return null;
                    }

                    return [
                        'priority' => $index + 1,
                        'label' => self::SOFT_OBJECTIVE_LABELS[$key],
                        'value' => self::formatQualityMeasure($key, (float) $raw),
                    ];
                })
                ->filter()
                ->values()
                ->all();

            if ($rows !== []) {
                return $rows;
            }
        }

        $weights = data_get($snapshot, 'constraint_profile.soft_weights', []);
        $constraints = data_get($snapshot, 'constraint_profile.soft_constraints', $snapshot['soft_constraints'] ?? []);
        $terms = data_get(self::solverResult($record), 'objective_details.terms', []);

        $weights = is_array($weights) ? $weights : [];
        $terms = is_array($terms) ? $terms : [];
        $constraints = is_array($constraints) && $constraints !== [] ? $constraints : array_keys($terms);

        return collect($constraints)
            ->filter(fn (mixed $constraint): bool => is_string($constraint) && $constraint !== '' && is_array($terms[$constraint] ?? null))
            ->unique()
            ->values()
            ->map(function (string $constraint) use ($weights, $terms): array {
                /** @var array<string, mixed> $term */
                $term = $terms[$constraint];

                return [
                    'priority' => null,
                    'label' => self::SOFT_OBJECTIVE_LABELS[$constraint] ?? Str::headline($constraint),
                    'value' => sprintf(
                        'Raw %s; weight %s; weighted contribution %s.',
                        self::displayValue($term['raw'] ?? null),
                        self::displayValue($term['weight'] ?? $weights[$constraint] ?? null),
                        self::displayValue($term['weighted'] ?? null),
                    ),
                ];
            })
            ->all();
    }

    private static function formatQualityMeasure(string $key, float $raw): string
    {
        return match ($key) {
            'cohort_mode_switches' => number_format($raw, 0).' '.Str::plural('switch', (int) round($raw)),
            'cohort_idle_time', 'faculty_idle_time' => number_format($raw, 0).' '.Str::plural('minute', (int) round($raw)),
            'faculty_load_imbalance' => number_format($raw / 100, 2).' load units',
            'room_seat_waste' => number_format($raw, 0).' '.Str::plural('seat', (int) round($raw)),
            'stable_earlier_placement' => number_format($raw, 0).' '.Str::plural('point', (int) round($raw)),
            default => self::displayValue($raw),
        };
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
            return '—';
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    /**
     * @param  array<string, mixed>|null  $conflictDiagnosis
     * @return list<array<string, mixed>>
     */
    private static function findingRows(
        ScheduleGenerationRun $record,
        ?int $termId = null,
        ?array $conflictDiagnosis = null,
    ): array {
        $latestValidation = self::currentRevalidation($record);
        $findings = $latestValidation !== []
            ? ($latestValidation['findings'] ?? null)
            : (self::solverResult($record)['findings'] ?? null);

        $validFindings = is_array($findings)
            ? collect($findings)->filter(fn (mixed $finding): bool => is_array($finding))->values()
            : collect();

        $effectiveTermId = $termId ?? $record->term_id;
        $diagnosedItems = is_array($conflictDiagnosis['items'] ?? null) ? $conflictDiagnosis['items'] : [];
        $hasDiagnosedItems = $diagnosedItems !== [];

        $diagnosticRows = [];
        foreach ($diagnosedItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $involved = is_array($item['involved'] ?? null) ? array_values(array_filter($item['involved'])) : [];
            $fixes = is_array($item['how_to_fix'] ?? null) ? array_values(array_filter($item['how_to_fix'])) : [];
            $parts = [
                trim((string) ($item['title'] ?? '')),
                $involved !== [] ? 'Involved: '.implode(', ', array_slice($involved, 0, 4)).'.' : '',
                trim((string) ($item['why'] ?? '')),
                $fixes !== [] ? 'What you can do: '.implode(' ', array_slice($fixes, 0, 2)) : '',
            ];
            $diagnosticRows[] = [
                'code' => 'diagnosed_conflict',
                'severity' => ($item['severity'] ?? 'danger') === 'danger' ? 'blocking' : 'warning',
                'constraint' => (string) ($item['category'] ?? 'Conflict'),
                'constraint_label' => (string) ($item['category'] ?? 'Conflict'),
                'message' => implode(' ', array_filter($parts)),
                'source_label' => ! empty($item['action_label'])
                    ? (string) $item['action_label']
                    : ($involved !== [] ? (string) $involved[0] : 'Timetable inputs'),
                'source_url' => ! empty($item['action_url']) ? (string) $item['action_url'] : null,
            ];
        }

        if ($validFindings->isEmpty()) {
            return $diagnosticRows;
        }

        $idsByType = $validFindings
            ->filter(fn (array $finding): bool => is_numeric($finding['source_id'] ?? null))
            ->groupBy(fn (array $finding): string => (string) ($finding['source_type'] ?? 'source'))
            ->map(fn ($group) => $group->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all());

        $preloaded = [
            'schedule_run' => $idsByType->has('schedule_run') ? ScheduleGenerationRun::query()->whereIn('id', $idsByType->get('schedule_run'))->get()->keyBy('id') : collect(),
            'scheduling_demand' => $idsByType->has('scheduling_demand')
                ? SchedulingDemand::query()
                    ->whereIn('id', $idsByType->get('scheduling_demand'))
                    ->with([
                        'courseComponent.courseSpecification.course:id,code',
                        'sectionDeliveryGroup.section:id,class_reference,code',
                        'sectionDeliveryGroup.section.cohorts:id,reference',
                        'termOffering.curriculumEntry.courseSpecification.course:id,code',
                    ])
                    ->get()
                    ->keyBy('id')
                : collect(),
            'room' => $idsByType->has('room') ? Room::query()->whereIn('id', $idsByType->get('room'))->get()->keyBy('id') : collect(),
            'term' => $idsByType->has('term') ? Term::query()->whereIn('id', $idsByType->get('term'))->get()->keyBy('id') : collect(),
            'faculty_qualification' => $idsByType->has('faculty_qualification')
                ? FacultyQualification::query()
                    ->whereIn('id', $idsByType->get('faculty_qualification'))
                    ->with(['faculty:id,name', 'course:id,code'])
                    ->get()
                    ->keyBy('id')
                : collect(),
            'faculty_term_load_override' => $idsByType->has('faculty_term_load_override')
                ? FacultyTermLoadOverride::query()
                    ->whereIn('id', $idsByType->get('faculty_term_load_override'))
                    ->with('faculty:id,name')
                    ->get()
                    ->keyBy('id')
                : collect(),
            'calendar_event' => $idsByType->has('calendar_event') ? CalendarEvent::query()->whereIn('id', $idsByType->get('calendar_event'))->get()->keyBy('id') : collect(),
        ];

        $entityLabels = app(TimetableConflictDiagnosticService::class)->entityLabelsForRun($record);

        $genericEnvelopeCodes = ['solver_infeasible', 'solver_unassigned', 'solver_reason'];

        $rawRows = $validFindings
            ->reject(function (array $finding) use ($hasDiagnosedItems, $genericEnvelopeCodes): bool {
                if (! $hasDiagnosedItems) {
                    return false;
                }

                $code = strtolower((string) ($finding['code'] ?? ''));
                $sourceType = (string) ($finding['source_type'] ?? 'schedule_run');

                return in_array($code, $genericEnvelopeCodes, true) && $sourceType === 'schedule_run';
            })
            ->map(function (array $finding) use ($effectiveTermId, $preloaded, $entityLabels): array {
                $source = self::sourcePresentation($finding, $effectiveTermId, $preloaded, $entityLabels);
                $constraint = (string) ($finding['constraint'] ?? '');

                return [
                    ...$finding,
                    'constraint_label' => self::humanConstraintLabel($constraint),
                    'source_label' => $source['label'],
                    'source_url' => $source['url'],
                ];
            })
            ->values()
            ->all();

        return [...$diagnosticRows, ...$rawRows];
    }

    /**
     * @param  class-string  $resource
     */
    private static function viewUrl(?Model $record, string $resource, array $parameters = []): ?string
    {
        if (! $record instanceof Model || Gate::denies('view', $record)) {
            return null;
        }

        /** @var class-string<\Filament\Resources\Resource> $resource */
        return $resource::getUrl('view', ['record' => $record, ...$parameters]);
    }

    /**
     * @param  class-string  $resource
     */
    private static function editUrl(?Model $record, string $resource, array $parameters = []): ?string
    {
        if (! $record instanceof Model || Gate::denies('update', $record)) {
            return null;
        }

        /** @var class-string<\Filament\Resources\Resource> $resource */
        return $resource::getUrl('edit', ['record' => $record, ...$parameters]);
    }
}
