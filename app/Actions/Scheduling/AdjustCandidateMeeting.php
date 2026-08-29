<?php

namespace App\Actions\Scheduling;

use App\Models\CandidateScheduleRow;
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
    public function __construct(private readonly ScheduleAssignmentRevalidationService $revalidator) {}

    /**
     * @param  array{faculty_user_id?: int, room_id?: int|null, day_of_week?: int, starts_at?: string, ends_at?: string}  $assignment
     */
    public function execute(
        CandidateScheduleRow $requestedRow,
        User $actor,
        array $assignment,
        string $reason,
        ?string $authority = null,
    ): ScheduleGenerationRun {
        Gate::forUser($actor)->authorize('reviewCandidates', $requestedRow->scheduleRun);

        return DB::transaction(function () use ($requestedRow, $actor, $assignment, $reason, $authority): ScheduleGenerationRun {
            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => 'A candidate-adjustment reason is required.']);
            }

            Term::query()->whereKey($requestedRow->scheduleRun->term_id)->lockForUpdate()->firstOrFail();
            $run = ScheduleGenerationRun::query()->whereKey($requestedRow->schedule_run_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('reviewCandidates', $run);

            if ($run->status !== ScheduleGenerationRun::StatusUnderReview || $run->candidate_state === 'Stale') {
                throw ValidationException::withMessages(['candidate' => 'Only the current non-stale candidate can be adjusted.']);
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
            $validation = $this->revalidator->validateCandidateSet($run, $payloads);

            if (! $validation->passes()) {
                throw ValidationException::withMessages([
                    'candidate' => $validation->blockingFindings()[0]['message'] ?? 'The local adjustment violates a hard scheduling rule.',
                ]);
            }

            $successor = $run->replicate();
            $adjustmentSnapshot = [
                'source_run_id' => $run->id,
                'source_candidate_version' => $run->candidate_version,
                'requested_candidate_row_id' => $target->id,
                'assignment' => $assignment,
                'reason' => $reason,
                'authority' => $authority ?? (string) $actor->id,
            ];
            $successor->forceFill([
                'status' => ScheduleGenerationRun::StatusUnderReview,
                'input_snapshot' => [
                    ...$run->input_snapshot,
                    'candidate_adjustment' => $adjustmentSnapshot,
                ],
                'input_hash' => hash('sha256', $run->input_hash.'|candidate-adjustment|'.json_encode($adjustmentSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'objective_value' => null,
                'quality_measures' => null,
                'diagnostics' => [
                    ...($run->diagnostics ?? []),
                    'current_revalidation' => [
                        'status' => 'accepted',
                        'context' => 'candidate_correction',
                        'findings' => [],
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

            foreach ($rows as $index => $row) {
                $successor->candidateRows()->create([
                    ...$payloads[$index],
                    'supersedes_candidate_row_id' => $row->id,
                    'change_type' => $row->is($target) ? 'LocalAdjustment' : 'Unchanged',
                    'override_authority' => $authority ?? (string) $actor->id,
                    'override_reason' => $reason,
                ]);
            }

            $run->forceFill(['status' => ScheduleGenerationRun::StatusSuperseded, 'candidate_state' => 'Superseded'])->save();

            $timestamp = CarbonImmutable::now(config('app.timezone'));
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
