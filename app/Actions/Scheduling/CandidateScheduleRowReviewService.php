<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CandidateScheduleRowReviewService
{
    public function __construct(
        private readonly ScheduleAssignmentRevalidationService $revalidator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function revise(
        CandidateScheduleRow $candidateRow,
        array $data,
        User $actor,
    ): ScheduleGenerationRun {
        $proposal = $this->validatedRevision($data);

        $outcome = DB::transaction(function () use ($candidateRow, $proposal, $actor): array {
            /** @var CandidateScheduleRow $lockedRow */
            $lockedRow = CandidateScheduleRow::query()
                ->lockForUpdate()
                ->findOrFail($candidateRow->id);
            /** @var ScheduleGenerationRun $run */
            $run = ScheduleGenerationRun::query()
                ->lockForUpdate()
                ->findOrFail($lockedRow->schedule_run_id);

            Gate::forUser($actor)->authorize('reviewCandidates', $run);

            if ($run->status !== ScheduleGenerationRun::StatusUnderReview) {
                throw ValidationException::withMessages([
                    'status' => 'Only an under-review schedule can receive a candidate correction.',
                ]);
            }

            $rows = CandidateScheduleRow::query()
                ->where('schedule_run_id', $run->id)
                ->orderBy('scheduling_demand_id')
                ->orderBy('meeting_sequence')
                ->lockForUpdate()
                ->get();
            $identity = $this->identity($lockedRow->scheduling_demand_id, $lockedRow->meeting_sequence);
            $assignments = $rows
                ->map(fn (CandidateScheduleRow $row): array => $this->candidatePayload($row, $row->id === $lockedRow->id ? $proposal : []))
                ->values()
                ->all();

            return $this->validateAndApply(
                run: $run,
                assignments: $assignments,
                existingRows: $rows,
                affectedIdentities: [$identity],
                actor: $actor,
                authority: $proposal['override_authority'],
                reason: $proposal['override_reason'],
                context: 'candidate_correction',
            );
        }, attempts: 5);

        return $this->resolvedRun($outcome);
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     */
    public function replace(
        ScheduleGenerationRun $run,
        array $assignments,
        User $actor,
        string $authority,
        string $reason,
    ): ScheduleGenerationRun {
        $assignments = $this->validatedReplacement($assignments);
        ['override_authority' => $authority, 'override_reason' => $reason] = $this->validatedEvidence($authority, $reason);

        $outcome = DB::transaction(function () use ($run, $assignments, $actor, $authority, $reason): array {
            /** @var ScheduleGenerationRun $lockedRun */
            $lockedRun = ScheduleGenerationRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);

            Gate::forUser($actor)->authorize('reviewCandidates', $lockedRun);

            if (! in_array($lockedRun->status, [
                ScheduleGenerationRun::StatusUnderReview,
                ScheduleGenerationRun::StatusBlocked,
                ScheduleGenerationRun::StatusFailed,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only an under-review, blocked, or failed run can receive a Manual Schedule Override.',
                ]);
            }

            $rows = CandidateScheduleRow::query()
                ->where('schedule_run_id', $lockedRun->id)
                ->orderBy('scheduling_demand_id')
                ->orderBy('meeting_sequence')
                ->lockForUpdate()
                ->get();
            $affected = collect($assignments)
                ->map(fn (array $assignment): string => $this->identity(
                    (int) $assignment['scheduling_demand_id'],
                    (int) $assignment['meeting_sequence'],
                ))
                ->all();

            return $this->validateAndApply(
                run: $lockedRun,
                assignments: $assignments,
                existingRows: $rows,
                affectedIdentities: $affected,
                actor: $actor,
                authority: $authority,
                reason: $reason,
                context: 'manual_schedule_override',
            );
        }, attempts: 5);

        return $this->resolvedRun($outcome);
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @param  Collection<int, CandidateScheduleRow>  $existingRows
     * @param  list<string>  $affectedIdentities
     * @return array{run:ScheduleGenerationRun,validation:ScheduleValidationResult}
     */
    private function validateAndApply(
        ScheduleGenerationRun $run,
        array $assignments,
        Collection $existingRows,
        array $affectedIdentities,
        User $actor,
        string $authority,
        string $reason,
        string $context,
    ): array {
        $manualWarning = [[
            'type' => $context,
            'message' => $context === 'candidate_correction'
                ? 'Authorized staff corrected this candidate assignment.'
                : 'Authorized staff supplied this Manual Schedule Override assignment.',
        ]];
        $assignments = collect($assignments)
            ->map(function (array $assignment) use ($affectedIdentities, $manualWarning): array {
                $identity = $this->identity(
                    (int) ($assignment['scheduling_demand_id'] ?? 0),
                    (int) ($assignment['meeting_sequence'] ?? 0),
                );

                if (! in_array($identity, $affectedIdentities, true)) {
                    return $assignment;
                }

                return [
                    ...$assignment,
                    'status' => CandidateScheduleRow::StatusWarning,
                    'scores' => [],
                    'warnings' => $manualWarning,
                    'violations' => [],
                ];
            })
            ->values()
            ->all();
        $validation = $this->revalidator->validateCandidateSet($run, $assignments);
        $this->storeDiagnostics($run, $validation, $context, $actor);

        if (! $validation->passes()) {
            return ['run' => $run->fresh(), 'validation' => $validation];
        }

        $existingByIdentity = $existingRows->keyBy(
            fn (CandidateScheduleRow $row): string => $this->identity($row->scheduling_demand_id, $row->meeting_sequence),
        );

        CandidateScheduleRow::query()
            ->where('schedule_run_id', $run->id)
            ->delete();

        foreach ($validation->candidateRows() as $candidate) {
            $identity = $this->identity(
                (int) $candidate['scheduling_demand_id'],
                (int) $candidate['meeting_sequence'],
            );
            $affected = in_array($identity, $affectedIdentities, true);
            $previous = $existingByIdentity->get($identity);

            CandidateScheduleRow::query()->create([
                ...$candidate,
                'status' => $affected ? CandidateScheduleRow::StatusWarning : $candidate['status'],
                'scores' => $affected ? null : $candidate['scores'],
                'warnings' => $affected ? $manualWarning : $candidate['warnings'],
                'violations' => null,
                'override_authority' => $affected ? $authority : $previous?->override_authority,
                'override_reason' => $affected ? $reason : $previous?->override_reason,
            ]);
        }

        $run->forceFill(['status' => ScheduleGenerationRun::StatusUnderReview])->save();
        $this->recordActivity($run, $actor, $authority, $reason, $affectedIdentities, $context);

        return ['run' => $run->fresh(['candidateRows']), 'validation' => $validation];
    }

    /**
     * @param  array{run:ScheduleGenerationRun,validation:ScheduleValidationResult}  $outcome
     */
    private function resolvedRun(array $outcome): ScheduleGenerationRun
    {
        if ($outcome['validation']->passes()) {
            return $outcome['run'];
        }

        $first = $outcome['validation']->blockingFindings()[0] ?? null;

        throw ValidationException::withMessages([
            'candidate_schedule_rows' => is_array($first)
                ? (string) $first['message']
                : 'The complete candidate schedule failed current hard-constraint validation.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{faculty_user_id:int,room_id:int|null,day_of_week:int,starts_at:string,ends_at:string,time_block_key:string|null,override_authority:string,override_reason:string}
     */
    private function validatedRevision(array $data): array
    {
        ['override_authority' => $authority, 'override_reason' => $reason] = $this->validatedEvidence(
            (string) ($data['override_authority'] ?? ''),
            (string) ($data['override_reason'] ?? ''),
        );
        $payload = [
            'faculty_user_id' => $data['faculty_user_id'] ?? null,
            'room_id' => $data['room_id'] ?? null,
            'day_of_week' => $data['day_of_week'] ?? null,
            'starts_at' => $this->timeValue($data['starts_at'] ?? null),
            'ends_at' => $this->timeValue($data['ends_at'] ?? null),
            'time_block_key' => filled($data['time_block_key'] ?? null) ? trim((string) $data['time_block_key']) : null,
            'override_authority' => $authority,
            'override_reason' => $reason,
        ];
        $validator = Validator::make($payload, [
            'faculty_user_id' => ['required', 'integer', 'exists:users,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'starts_at' => ['required', 'date_format:H:i:s'],
            'ends_at' => ['required', 'date_format:H:i:s', 'after:starts_at'],
            'time_block_key' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array{faculty_user_id:int,room_id:int|null,day_of_week:int,starts_at:string,ends_at:string,time_block_key:string|null,override_authority:string,override_reason:string} $payload */
        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function validatedReplacement(array $assignments): array
    {
        $payload = ['assignments' => collect($assignments)
            ->map(fn (array $assignment): array => [
                ...$assignment,
                'starts_at' => $this->timeValue($assignment['starts_at'] ?? null),
                'ends_at' => $this->timeValue($assignment['ends_at'] ?? null),
            ])
            ->values()
            ->all()];
        $validator = Validator::make($payload, [
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.scheduling_demand_id' => ['required', 'integer', 'exists:scheduling_demands,id'],
            'assignments.*.meeting_sequence' => ['required', 'integer', 'min:1'],
            'assignments.*.faculty_user_id' => ['required', 'integer', 'exists:users,id'],
            'assignments.*.room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'assignments.*.day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'assignments.*.starts_at' => ['required', 'date_format:H:i:s'],
            'assignments.*.ends_at' => ['required', 'date_format:H:i:s'],
            'assignments.*.time_block_key' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $payload['assignments'];
    }

    /**
     * @return array{override_authority:string,override_reason:string}
     */
    private function validatedEvidence(string $authority, string $reason): array
    {
        $payload = [
            'override_authority' => trim($authority),
            'override_reason' => trim($reason),
        ];
        $validator = Validator::make($payload, [
            'override_authority' => ['required', 'string', 'max:255'],
            'override_reason' => ['required', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function candidatePayload(CandidateScheduleRow $row, array $overrides = []): array
    {
        $scores = $row->getAttribute('scores');
        $warnings = $row->getAttribute('warnings');
        $violations = $row->getAttribute('violations');

        return [
            'scheduling_demand_id' => (int) $row->scheduling_demand_id,
            'meeting_sequence' => (int) $row->meeting_sequence,
            'faculty_user_id' => (int) ($overrides['faculty_user_id'] ?? $row->faculty_user_id),
            'room_id' => array_key_exists('room_id', $overrides) ? $overrides['room_id'] : $row->room_id,
            'day_of_week' => (int) ($overrides['day_of_week'] ?? $row->day_of_week),
            'starts_at' => $this->timeValue($overrides['starts_at'] ?? $row->starts_at),
            'ends_at' => $this->timeValue($overrides['ends_at'] ?? $row->ends_at),
            'time_block_key' => array_key_exists('time_block_key', $overrides) ? $overrides['time_block_key'] : $row->time_block_key,
            'status' => $overrides === [] ? $row->status : CandidateScheduleRow::StatusWarning,
            'scores' => $overrides === [] && is_array($scores) ? $scores : [],
            'warnings' => $overrides === [] && is_array($warnings) ? $warnings : [],
            'violations' => $overrides === [] && is_array($violations) ? $violations : [],
        ];
    }

    private function storeDiagnostics(
        ScheduleGenerationRun $run,
        ScheduleValidationResult $validation,
        string $context,
        User $actor,
    ): void {
        $currentDiagnostics = $run->getAttribute('diagnostics');
        $diagnostics = is_array($currentDiagnostics) ? $currentDiagnostics : [];
        $diagnostics['current_revalidation'] = [
            'context' => $context,
            'status' => $validation->passes() ? 'accepted' : 'blocked',
            'validated_at' => now()->toIso8601String(),
            'actor_id' => (int) $actor->id,
            'summary' => $validation->summary(),
            'findings' => $validation->findings(),
        ];
        $run->forceFill(['diagnostics' => $diagnostics])->save();
    }

    /**
     * @param  list<string>  $affectedIdentities
     */
    private function recordActivity(
        ScheduleGenerationRun $run,
        User $actor,
        string $authority,
        string $reason,
        array $affectedIdentities,
        string $context,
    ): void {
        $timestamp = CarbonImmutable::now(config('app.timezone'));

        DB::table('activity_log')->insert([
            'log_name' => 'scheduling',
            'description' => $context === 'candidate_correction'
                ? 'Candidate schedule assignment corrected and revalidated.'
                : 'Manual Schedule Override recorded and revalidated.',
            'subject_type' => ScheduleGenerationRun::class,
            'subject_id' => $run->id,
            'event' => $context,
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'properties' => json_encode([
                'authority' => $authority,
                'reason' => $reason,
                'affected_assignments' => $affectedIdentities,
                'validation_result' => 'accepted',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]);
    }

    private function identity(int $demandId, int $meetingSequence): string
    {
        return $demandId.':'.$meetingSequence;
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = (string) $value;

        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
