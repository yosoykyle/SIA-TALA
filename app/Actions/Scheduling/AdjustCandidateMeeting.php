<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdjustCandidateMeeting
{
    public function __construct(
        private readonly ScheduleAssignmentRevalidationService $revalidator,
        private readonly ?RecordFacultyAvailabilityDeclaration $recordAvailability = null,
        private readonly ?ScheduleSolverSnapshotService $snapshots = null,
    ) {}

    /**
     * @param  array{faculty_user_id?: int, room_id?: int|null, day_of_week?: int, starts_at?: string, ends_at?: string}  $assignment
     */
    public function execute(
        CandidateScheduleRow $requestedRow,
        User $actor,
        array $assignment,
        ?string $reason = null,
        ?string $authority = null,
        bool $expandTeacherAvailability = false,
        ?string $availabilityExpansionReason = null,
    ): ScheduleGenerationRun {
        Gate::forUser($actor)->authorize('reviewCandidates', $requestedRow->scheduleRun);

        if ($expandTeacherAvailability && ! $actor->hasRole(User::StaffRoleRegistrar)) {
            abort(403);
        }

        return DB::transaction(function () use (
            $requestedRow,
            $actor,
            $assignment,
            $reason,
            $authority,
            $expandTeacherAvailability,
            $availabilityExpansionReason,
        ): ScheduleGenerationRun {
            $term = Term::query()->whereKey($requestedRow->scheduleRun->term_id)->lockForUpdate()->firstOrFail();
            $run = ScheduleGenerationRun::query()->whereKey($requestedRow->schedule_run_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('reviewCandidates', $run);

            if ($run->status !== ScheduleGenerationRun::StatusUnderReview || $run->candidate_state === 'Stale') {
                throw ValidationException::withMessages(['candidate' => 'Only the current non-stale candidate can be adjusted.']);
            }

            $reason = trim((string) ($reason ?? ''));
            $authority = filled($authority) ? trim((string) $authority) : null;

            if ($reason === '' && $run->status === ScheduleGenerationRun::StatusUnderReview) {
                $reason = 'Registrar draft adjustment';
            }

            if ($authority === null && $run->status === ScheduleGenerationRun::StatusUnderReview) {
                $authority = 'Registrar draft adjustment';
            }

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'A candidate-adjustment reason is required.']);
            }

            $rows = CandidateScheduleRow::query()
                ->where('schedule_run_id', $run->id)
                ->with(['schedulingDemand.termOffering', 'schedulingDemand.sectionDeliveryGroup'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $target = $rows->firstWhere('id', $requestedRow->id);

            if (! $target instanceof CandidateScheduleRow) {
                throw ValidationException::withMessages(['candidate' => 'The requested candidate meeting is stale.']);
            }

            $payloads = $rows->map(function (CandidateScheduleRow $row) use ($run, $target, $assignment): array {
                $payload = $this->payload($row);

                return $row->is($target)
                    ? $this->withCapturedTimeBlock($run, array_replace($payload, $assignment))
                    : $payload;
            })->values()->all();

            $targetIndex = $rows->search(fn (CandidateScheduleRow $row): bool => $row->is($target));
            $targetPayload = is_int($targetIndex) ? ($payloads[$targetIndex] ?? null) : null;
            $expandedDeclaration = null;
            $expansionReason = null;

            if ($expandTeacherAvailability && is_array($targetPayload)) {
                $expansionReason = trim((string) ($availabilityExpansionReason ?? ''));
                if ($expansionReason === '') {
                    $expansionReason = $reason;
                }
                if ($expansionReason === '') {
                    throw ValidationException::withMessages([
                        'availability_expansion_reason' => 'A reason is required when expanding a teacher\'s availability for a scheduled class.',
                    ]);
                }

                $targetFaculty = User::query()->findOrFail((int) $targetPayload['faculty_user_id']);
                $recorder = $this->recordAvailability ?? app(RecordFacultyAvailabilityDeclaration::class);
                $expandedDeclaration = $recorder->expandForScheduledSlot(
                    term: $term,
                    faculty: $targetFaculty,
                    actor: $actor,
                    dayOfWeek: (int) $targetPayload['day_of_week'],
                    startsAt: (string) $targetPayload['starts_at'],
                    endsAt: (string) $targetPayload['ends_at'],
                    reason: $expansionReason,
                    preserveRunId: (int) $run->id,
                    context: [
                        'source' => 'candidate_adjustment',
                        'schedule_run_id' => (int) $run->id,
                        'candidate_row_id' => (int) $target->id,
                        'scheduling_demand_id' => (int) $target->scheduling_demand_id,
                    ],
                );
            }

            $validation = $this->revalidator->validateCandidateSet($run, $payloads);

            if (! $validation->passes()) {
                throw ValidationException::withMessages([
                    'candidate' => $validation->blockingFindings()[0]['message'] ?? 'The local adjustment violates a hard scheduling rule.',
                ]);
            }

            $successor = $run->replicate();
            $diagnostics = is_array($run->diagnostics) ? $run->diagnostics : [];
            $rootSolverRunId = data_get($diagnostics, 'root_solver_run_id');
            if (! is_numeric($rootSolverRunId) || (int) $rootSolverRunId <= 0) {
                $rootSolverRunId = RevertCandidateRun::isLocalAdjustment($run)
                    ? (RevertCandidateRun::rootSolverRun($run)?->id ?? $run->id)
                    : $run->id;
            }
            $manualEditCount = RevertCandidateRun::manualEditCount($run) + 1;
            $sourceEvidence = [
                'run_id' => $run->id,
                'solver_version' => $run->solver_version,
                'runtime_ms' => $run->runtime_ms,
                'result' => $diagnostics['solver_result'] ?? null,
                'dispatch' => $diagnostics['solver_dispatch'] ?? null,
            ];
            unset($diagnostics['solver_result'], $diagnostics['solver_dispatch']);
            $adjustmentSnapshot = [
                'source_run_id' => $run->id,
                'source_candidate_version' => $run->candidate_version,
                'requested_candidate_row_id' => $target->id,
                'assignment' => $assignment,
                'reason' => $reason,
                'authority' => $authority ?? (string) $actor->id,
            ];

            $successorInputSnapshot = is_array($run->input_snapshot) ? $run->input_snapshot : [];
            if ($expandedDeclaration instanceof FacultyAvailabilityDeclaration) {
                $snapshotService = $this->snapshots ?? app(ScheduleSolverSnapshotService::class);
                $liveSnapshot = $snapshotService->currentForRun($run);
                $successorInputSnapshot['faculty_availability'] = $liveSnapshot['faculty_availability']
                    ?? ($successorInputSnapshot['faculty_availability'] ?? []);
                $successorInputSnapshot['calendar_blocks'] = $liveSnapshot['calendar_blocks']
                    ?? ($successorInputSnapshot['calendar_blocks'] ?? []);
                $adjustmentSnapshot['availability_expansion'] = [
                    'faculty_user_id' => (int) $expandedDeclaration->faculty_user_id,
                    'declaration_id' => (int) $expandedDeclaration->id,
                    'declaration_version' => (int) $expandedDeclaration->version,
                    'reason' => $expansionReason,
                ];
            }
            $successorInputSnapshot['candidate_adjustment'] = $adjustmentSnapshot;

            $successor->forceFill([
                'status' => ScheduleGenerationRun::StatusUnderReview,
                'input_snapshot' => $successorInputSnapshot,
                'input_hash' => hash('sha256', $run->input_hash.'|candidate-adjustment|'.json_encode($adjustmentSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'objective_value' => null,
                'quality_measures' => null,
                'runtime_ms' => null,
                'solver_version' => 'local-adjustment-hard-validation',
                'diagnostics' => [
                    ...$diagnostics,
                    'candidate_origin' => 'LocalAdjustment',
                    'root_solver_run_id' => (int) $rootSolverRunId,
                    'manual_edit_count' => $manualEditCount,
                    'source_solver_evidence' => $sourceEvidence,
                    'current_revalidation' => [
                        'status' => 'accepted',
                        'context' => 'candidate_correction',
                        'findings' => [],
                        'summary' => $validation->summary(),
                    ],
                    'quality_comparison' => [
                        'status' => 'requires_attributable_review',
                        'reason' => 'A local correction is hard-constraint validated but is not re-optimized by CP-SAT.',
                        'source_run_id' => $run->id,
                    ],
                ],
                'candidate_key' => 'local-adjustment-'.Str::uuid(),
                'candidate_version' => ((int) $run->candidate_version) + 1,
                'candidate_state' => 'UnderReview',
                'candidate_reviewed_by' => null,
                'candidate_reviewed_at' => null,
                'candidate_review_reason' => $reason,
                'published_by' => null,
                'published_at' => null,
                'publication_version' => null,
            ])->save();

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $timestampString = $timestamp->toDateTimeString();
            $rowsToInsert = [];

            foreach ($rows as $index => $row) {
                $payload = $payloads[$index];
                $rowsToInsert[] = [
                    'schedule_run_id' => $successor->id,
                    'supersedes_candidate_row_id' => $row->id,
                    'scheduling_demand_id' => $payload['scheduling_demand_id'],
                    'meeting_sequence' => $payload['meeting_sequence'],
                    'faculty_user_id' => $payload['faculty_user_id'],
                    'room_id' => $payload['room_id'] ?? null,
                    'day_of_week' => $payload['day_of_week'],
                    'starts_at' => $payload['starts_at'],
                    'ends_at' => $payload['ends_at'],
                    'time_block_key' => $payload['time_block_key'] ?? null,
                    'status' => $payload['status'],
                    'change_type' => $row->is($target) ? 'LocalAdjustment' : 'Unchanged',
                    'scores' => is_array($payload['scores'] ?? null)
                        ? json_encode($payload['scores'], JSON_THROW_ON_ERROR)
                        : ($payload['scores'] ?? null),
                    'warnings' => is_array($payload['warnings'] ?? null)
                        ? json_encode($payload['warnings'], JSON_THROW_ON_ERROR)
                        : ($payload['warnings'] ?? null),
                    'violations' => is_array($payload['violations'] ?? null)
                        ? json_encode($payload['violations'], JSON_THROW_ON_ERROR)
                        : ($payload['violations'] ?? null),
                    'override_authority' => $authority ?? (string) $actor->id,
                    'override_reason' => $reason,
                    'created_at' => $timestampString,
                    'updated_at' => $timestampString,
                ];
            }

            if ($rowsToInsert !== []) {
                foreach (array_chunk($rowsToInsert, 1000) as $chunk) {
                    CandidateScheduleRow::query()->insert($chunk);
                }
            }

            $run->forceFill(['status' => ScheduleGenerationRun::StatusSuperseded, 'candidate_state' => 'Superseded'])->save();

            DB::table('activity_log')->insert([
                'log_name' => 'scheduling',
                'description' => 'Candidate schedule assignment corrected and revalidated as an immutable successor.',
                'subject_type' => ScheduleGenerationRun::class,
                'subject_id' => $successor->id,
                'event' => 'candidate_correction',
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'properties' => json_encode([
                    'source_run_id' => $run->id,
                    'authority' => $authority ?? (string) $actor->id,
                    'reason' => $reason,
                    'requested_candidate_row_id' => $target->id,
                    'validation_result' => 'accepted',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp->toDateTimeString(),
                'updated_at' => $timestamp->toDateTimeString(),
            ]);

            return $successor->fresh('candidateRows');
        }, attempts: 5);
    }

    /** @return array<string, mixed> */
    private function payload(CandidateScheduleRow $row): array
    {
        return [
            'scheduling_demand_id' => (int) $row->scheduling_demand_id,
            'meeting_sequence' => (int) $row->meeting_sequence,
            'faculty_user_id' => (int) $row->faculty_user_id,
            'room_id' => $row->room_id !== null ? (int) $row->room_id : null,
            'day_of_week' => (int) $row->day_of_week,
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
            'time_block_key' => $row->time_block_key,
            'status' => $row->status,
            'scores' => $row->scores ?? [],
            'warnings' => $row->warnings ?? [],
            'violations' => $row->violations ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    private function withCapturedTimeBlock(ScheduleGenerationRun $run, array $assignment): array
    {
        $snapshot = $run->input_snapshot;
        $slots = is_array($snapshot['time_slots'] ?? null) ? $snapshot['time_slots'] : [];
        $day = (int) ($assignment['day_of_week'] ?? 0);
        $startsAt = substr((string) ($assignment['starts_at'] ?? ''), 0, 5);
        $slot = collect($slots)->first(fn (mixed $candidate): bool => is_array($candidate)
            && (int) ($candidate['day_of_week'] ?? 0) === $day
            && substr((string) ($candidate['starts_at'] ?? ''), 0, 5) === $startsAt);

        $assignment['time_block_key'] = is_array($slot)
            ? (string) ($slot['time_block_key'] ?? '')
            : null;

        return $assignment;
    }
}
