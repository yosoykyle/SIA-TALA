<?php

namespace App\Actions\Scheduling;

use App\Models\FacultySubjectEligibility;
use App\Models\ScheduleDraftRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleCloudResultIngestor
{
    /**
     * @param  array<string, mixed>  $solverResult
     * @return array<string, mixed>
     */
    public function ingest(ScheduleGenerationRun $run, array $solverResult): array
    {
        return DB::transaction(function () use ($run, $solverResult): array {
            /** @var ScheduleGenerationRun $lockedRun */
            $lockedRun = ScheduleGenerationRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);

            if ($lockedRun->solver_input_snapshot === null) {
                throw ValidationException::withMessages([
                    'solver_input_snapshot' => 'Solver result ingestion requires an immutable input snapshot.',
                ]);
            }

            if ($lockedRun->status === ScheduleGenerationRun::StatusCommitted) {
                throw ValidationException::withMessages([
                    'status' => 'Committed schedule runs cannot ingest new solver results.',
                ]);
            }

            ScheduleDraftRow::query()
                ->where('generation_run_id', $lockedRun->id)
                ->delete();

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $draftRows = $this->draftRows($solverResult);
            $acceptedRows = collect();
            $summary = $this->emptySummary($timestamp, count($draftRows));

            foreach ($draftRows as $index => $rawRow) {
                if (! is_array($rawRow)) {
                    $summary['rejected_count']++;
                    $summary['rejected_rows'][] = [
                        'index' => $index,
                        'reason' => 'row_payload_must_be_an_object',
                    ];

                    continue;
                }

                $prepared = $this->prepareRow($lockedRun, $rawRow, $acceptedRows);

                if ($prepared['rejected_reason'] !== null) {
                    $summary['rejected_count']++;
                    $summary['rejected_rows'][] = [
                        'index' => $index,
                        'reason' => $prepared['rejected_reason'],
                    ];

                    continue;
                }

                /** @var array<string, mixed> $payload */
                $payload = $prepared['payload'];
                $status = $this->statusFor($rawRow, $prepared['conflicts'], $prepared['warnings']);

                ScheduleDraftRow::query()->create([
                    ...$payload,
                    'generation_run_id' => $lockedRun->id,
                    'status' => $status,
                    'conflict_payload' => $prepared['conflicts'] !== [] ? [
                        'source' => 'laravel_ingestion_validator',
                        'items' => $prepared['conflicts'],
                    ] : null,
                    'warning_payload' => $prepared['warnings'] !== [] ? [
                        'source' => 'solver_or_laravel_ingestion',
                        'items' => $prepared['warnings'],
                    ] : null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $summary['draft_row_count']++;
                $summary[$status.'_count']++;

                if (in_array($status, ScheduleDraftRow::committableStatuses(), true)) {
                    $acceptedRows->push($payload);
                }
            }

            $constraintSummary = $lockedRun->constraint_summary ?? [];
            $constraintSummary['solver_ingestion'] = $summary;

            $lockedRun->forceFill([
                'status' => ScheduleGenerationRun::StatusUnderReview,
                'constraint_summary' => $constraintSummary,
            ])->save();

            $run->refresh();

            return $summary;
        });
    }

    /**
     * @param  array<string, mixed>  $solverResult
     * @return list<mixed>
     */
    private function draftRows(array $solverResult): array
    {
        return is_array($solverResult['draft_rows'] ?? null)
            ? array_values($solverResult['draft_rows'])
            : [];
    }

    /**
     * @param  array<string, mixed>  $rawRow
     * @param  Collection<int, array<string, mixed>>  $acceptedRows
     * @return array{payload:array<string, mixed>, conflicts:list<array<string, mixed>>, warnings:list<array<string, mixed>>, rejected_reason:string|null}
     */
    private function prepareRow(ScheduleGenerationRun $run, array $rawRow, Collection $acceptedRows): array
    {
        $snapshot = $run->solver_input_snapshot ?? [];
        $sections = $this->snapshotSections($snapshot);
        $demandKeys = $this->snapshotDemandKeys($snapshot);
        $availability = $this->snapshotAvailability($snapshot);

        $sectionId = $this->integerValue($rawRow['section_id'] ?? null);
        $subjectId = $this->integerValue($rawRow['subject_id'] ?? null);

        if ($sectionId === null || $subjectId === null) {
            return $this->rejected('missing_section_or_subject_identifier');
        }

        if (! Section::query()->whereKey($sectionId)->where('term_id', $run->term_id)->exists()) {
            return $this->rejected('section_not_found_in_run_term');
        }

        if (! Subject::query()->whereKey($subjectId)->exists()) {
            return $this->rejected('subject_not_found');
        }

        $sectionSnapshot = $sections[$sectionId] ?? null;
        $facultyId = $this->validFacultyId($rawRow['faculty_id'] ?? null);
        $modality = $this->stringValue($rawRow['modality'] ?? null) ?? $sectionSnapshot['modality'] ?? null;
        $payload = [
            'section_id' => $sectionId,
            'subject_id' => $subjectId,
            'faculty_id' => $facultyId,
            'room' => $this->stringValue($rawRow['room'] ?? null),
            'day_of_week' => $this->integerValue($rawRow['day_of_week'] ?? null),
            'starts_at' => $this->timeValue($rawRow['starts_at'] ?? null),
            'ends_at' => $this->timeValue($rawRow['ends_at'] ?? null),
            'modality' => $modality,
        ];
        $conflicts = [];
        $warnings = $this->warningsFrom($rawRow);

        if (($rawRow['status'] ?? null) === ScheduleDraftRow::StatusConflict) {
            $conflicts[] = $this->conflict('solver_reported_conflict', 'The solver reported this row as a conflict.', $rawRow['conflict_payload'] ?? null);
        }

        if ($sectionSnapshot === null) {
            $conflicts[] = $this->conflict('section_not_in_snapshot', 'The section was not part of the immutable solver input snapshot.');
        }

        if (! isset($demandKeys[$sectionId.':'.$subjectId])) {
            $conflicts[] = $this->conflict('subject_not_in_curriculum_demand', 'The subject is not required for this section in the solver snapshot.');
        }

        if (($sectionSnapshot['max_seats'] ?? 0) > 30 || ($sectionSnapshot['enrolled_count'] ?? 0) > ($sectionSnapshot['max_seats'] ?? 0)) {
            $conflicts[] = $this->conflict('section_capacity_contract_violation', 'The section capacity snapshot violates the rescue capacity contract.');
        }

        $this->appendFieldConflicts($payload, $conflicts);
        $this->appendFacultyConflicts($run, $payload, $availability, $conflicts);
        $this->appendOverlapConflicts($run, $payload, $acceptedRows, $conflicts);

        return [
            'payload' => $payload,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
            'rejected_reason' => null,
        ];
    }

    /**
     * @return array{payload:array<string, mixed>, conflicts:list<array<string, mixed>>, warnings:list<array<string, mixed>>, rejected_reason:string|null}
     */
    private function rejected(string $reason): array
    {
        return [
            'payload' => [],
            'conflicts' => [],
            'warnings' => [],
            'rejected_reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function appendFieldConflicts(array $payload, array &$conflicts): void
    {
        foreach (['faculty_id', 'day_of_week', 'starts_at', 'ends_at', 'modality'] as $field) {
            if ($payload[$field] === null) {
                $conflicts[] = $this->conflict('missing_'.$field, "Missing required {$field}.");
            }
        }

        if ($payload['day_of_week'] !== null && ($payload['day_of_week'] < 1 || $payload['day_of_week'] > 7)) {
            $conflicts[] = $this->conflict('invalid_day_of_week', 'Schedule day must be from Monday to Sunday.');
        }

        if ($payload['modality'] !== null && ! in_array($payload['modality'], array_keys(SectionMeeting::modalityOptions()), true)) {
            $conflicts[] = $this->conflict('invalid_modality', 'Unsupported schedule modality.');
        }

        if ($payload['starts_at'] !== null && $payload['ends_at'] !== null && $payload['starts_at'] >= $payload['ends_at']) {
            $conflicts[] = $this->conflict('invalid_time_range', 'End time must be after the start time.');
        }

        if ($payload['modality'] !== null && $this->requiresRoom($payload['modality']) && $payload['room'] === null) {
            $conflicts[] = $this->conflict('missing_required_room', 'A room is required for on-site or blended meetings.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, list<array<string, mixed>>>  $availability
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function appendFacultyConflicts(
        ScheduleGenerationRun $run,
        array $payload,
        array $availability,
        array &$conflicts,
    ): void {
        if ($payload['faculty_id'] === null) {
            return;
        }

        if (! FacultySubjectEligibility::isActiveFor($payload['faculty_id'], $payload['subject_id'], (int) $run->term_id)) {
            $conflicts[] = $this->conflict('missing_faculty_subject_eligibility', 'The faculty is not approved to teach this subject for the selected term.');
        }

        if ($payload['day_of_week'] === null || $payload['starts_at'] === null || $payload['ends_at'] === null) {
            return;
        }

        $windows = $availability[$payload['faculty_id']] ?? [];

        if ($windows === []) {
            $conflicts[] = $this->conflict('missing_locked_faculty_availability', 'The faculty has no submitted or locked availability window in the snapshot.');

            return;
        }

        $insideWindow = collect($windows)->contains(fn (array $window): bool => (int) $window['day_of_week'] === $payload['day_of_week']
            && (string) $window['starts_at'] <= $payload['starts_at']
            && (string) $window['ends_at'] >= $payload['ends_at']);

        if (! $insideWindow) {
            $conflicts[] = $this->conflict('outside_faculty_availability', 'The proposed meeting is outside the faculty availability snapshot.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, array<string, mixed>>  $acceptedRows
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function appendOverlapConflicts(
        ScheduleGenerationRun $run,
        array $payload,
        Collection $acceptedRows,
        array &$conflicts,
    ): void {
        if ($payload['day_of_week'] === null || $payload['starts_at'] === null || $payload['ends_at'] === null) {
            return;
        }

        $overlappingMeetings = SectionMeeting::query()
            ->where('term_id', $run->term_id)
            ->where('day_of_week', $payload['day_of_week'])
            ->where('starts_at', '<', $payload['ends_at'])
            ->where('ends_at', '>', $payload['starts_at']);

        if ((clone $overlappingMeetings)->where('section_id', $payload['section_id'])->exists()) {
            $conflicts[] = $this->conflict('section_overlap', 'The section already has a committed meeting during this time.');
        }

        if ($payload['faculty_id'] !== null && (clone $overlappingMeetings)->where('faculty_id', $payload['faculty_id'])->exists()) {
            $conflicts[] = $this->conflict('faculty_overlap', 'The faculty already has a committed meeting during this time.');
        }

        if ($payload['room'] !== null && $payload['modality'] !== null && $this->requiresRoom($payload['modality']) && (clone $overlappingMeetings)->where('room', $payload['room'])->exists()) {
            $conflicts[] = $this->conflict('room_overlap', 'The room already has a committed meeting during this time.');
        }

        foreach ($acceptedRows as $acceptedRow) {
            if (! $this->overlaps($payload, $acceptedRow)) {
                continue;
            }

            if ($acceptedRow['section_id'] === $payload['section_id']) {
                $conflicts[] = $this->conflict('internal_section_overlap', 'The solver proposed two overlapping rows for the same section.');
            }

            if ($payload['faculty_id'] !== null && $acceptedRow['faculty_id'] === $payload['faculty_id']) {
                $conflicts[] = $this->conflict('internal_faculty_overlap', 'The solver proposed two overlapping rows for the same faculty.');
            }

            if ($payload['room'] !== null && $this->requiresRoom((string) $payload['modality']) && $acceptedRow['room'] === $payload['room']) {
                $conflicts[] = $this->conflict('internal_room_overlap', 'The solver proposed two overlapping rows for the same room.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function overlaps(array $left, array $right): bool
    {
        return $left['day_of_week'] === $right['day_of_week']
            && $left['starts_at'] < $right['ends_at']
            && $left['ends_at'] > $right['starts_at'];
    }

    /**
     * @param  array<string, mixed>  $rawRow
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<array<string, mixed>>  $warnings
     */
    private function statusFor(array $rawRow, array $conflicts, array $warnings): string
    {
        if ($conflicts !== []) {
            return ScheduleDraftRow::StatusConflict;
        }

        if (($rawRow['status'] ?? null) === ScheduleDraftRow::StatusWarning || $warnings !== []) {
            return ScheduleDraftRow::StatusWarning;
        }

        return ScheduleDraftRow::StatusOk;
    }

    /**
     * @param  array<string, mixed>  $rawRow
     * @return list<array<string, mixed>>
     */
    private function warningsFrom(array $rawRow): array
    {
        $warningPayload = $rawRow['warning_payload'] ?? [];

        if (! is_array($warningPayload) || $warningPayload === []) {
            return [];
        }

        return array_is_list($warningPayload)
            ? array_values($warningPayload)
            : [$warningPayload];
    }

    /**
     * @return array{type:string, message:string, context?:mixed}
     */
    private function conflict(string $type, string $message, mixed $context = null): array
    {
        $payload = [
            'type' => $type,
            'message' => $message,
        ];

        if ($context !== null) {
            $payload['context'] = $context;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function snapshotSections(array $snapshot): array
    {
        return collect($snapshot['sections'] ?? [])
            ->filter(fn (mixed $section): bool => is_array($section) && isset($section['section_id']))
            ->keyBy(fn (array $section): int => (int) $section['section_id'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, true>
     */
    private function snapshotDemandKeys(array $snapshot): array
    {
        return collect($snapshot['curriculum_subject_demand'] ?? [])
            ->filter(fn (mixed $demand): bool => is_array($demand) && isset($demand['section_id'], $demand['subject_id']))
            ->mapWithKeys(fn (array $demand): array => [
                ((int) $demand['section_id']).':'.((int) $demand['subject_id']) => true,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, list<array<string, mixed>>>
     */
    private function snapshotAvailability(array $snapshot): array
    {
        return collect($snapshot['faculty_availability'] ?? [])
            ->filter(fn (mixed $availability): bool => is_array($availability) && isset($availability['faculty_id']))
            ->mapWithKeys(fn (array $availability): array => [
                (int) $availability['faculty_id'] => collect($availability['windows'] ?? [])
                    ->filter(fn (mixed $window): bool => is_array($window))
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(CarbonImmutable $timestamp, int $solverRowCount): array
    {
        return [
            'status' => 'ingested',
            'ingested_at' => $timestamp->toIso8601String(),
            'solver_row_count' => $solverRowCount,
            'draft_row_count' => 0,
            'ok_count' => 0,
            'warning_count' => 0,
            'conflict_count' => 0,
            'rejected_count' => 0,
            'rejected_rows' => [],
        ];
    }

    private function validFacultyId(mixed $value): ?int
    {
        $facultyId = $this->integerValue($value);

        if ($facultyId === null) {
            return null;
        }

        return User::query()->whereKey($facultyId)->exists() ? $facultyId : null;
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;
        $time = strlen($time) === 5 ? $time.':00' : $time;

        return strlen($time) > 8 ? substr($time, 0, 8) : $time;
    }

    private function requiresRoom(string $modality): bool
    {
        return in_array($modality, ['on_site', 'blended'], true);
    }
}
