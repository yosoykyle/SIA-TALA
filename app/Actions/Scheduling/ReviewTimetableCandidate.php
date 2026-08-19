<?php

namespace App\Actions\Scheduling;

use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ReviewTimetableCandidate
{
    public function accept(ScheduleGenerationRun $run, User $actor, string $reason): ScheduleGenerationRun
    {
        return $this->record($run, $actor, 'Accepted', $reason);
    }

    public function reject(ScheduleGenerationRun $run, User $actor, string $reason): ScheduleGenerationRun
    {
        return $this->record($run, $actor, 'Rejected', $reason);
    }

    private function record(
        ScheduleGenerationRun $run,
        User $actor,
        string $decision,
        string $reason,
    ): ScheduleGenerationRun {
        Gate::forUser($actor)->authorize('reviewCandidates', $run);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'candidate_review_reason' => 'Candidate acceptance or rejection requires an attributable review reason.',
            ]);
        }

        return DB::transaction(function () use ($run, $actor, $decision, $reason): ScheduleGenerationRun {
            Term::query()->whereKey($run->term_id)->lockForUpdate()->firstOrFail();
            $locked = ScheduleGenerationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('reviewCandidates', $locked);

            if ($locked->status !== ScheduleGenerationRun::StatusUnderReview
                || in_array($locked->candidate_state, ['Accepted', 'Rejected', 'Stale', 'Superseded'], true)) {
                throw ValidationException::withMessages([
                    'candidate_state' => 'Only a current, unreviewed candidate can be accepted or rejected.',
                ]);
            }

            if (! $locked->candidateRows()->exists()) {
                throw ValidationException::withMessages([
                    'candidate_state' => 'A candidate must contain retained assignments before review.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));
            $locked->forceFill([
                'status' => $decision === 'Accepted'
                    ? ScheduleGenerationRun::StatusUnderReview
                    : ScheduleGenerationRun::StatusBlocked,
                'candidate_state' => $decision,
                'candidate_reviewed_by' => $actor->id,
                'candidate_reviewed_at' => $timestamp,
                'candidate_review_reason' => $reason,
            ])->save();

            $snapshot = $locked->input_snapshot;
            $operation = is_array($snapshot['operation'] ?? null) ? $snapshot['operation'] : [];
            $source = is_array($operation['source_candidate'] ?? null) ? $operation['source_candidate'] : [];

            if ($decision === 'Accepted' && ($operation['kind'] ?? null) === 'repair' && isset($source['run_id'])) {
                ScheduleGenerationRun::query()
                    ->whereKey((int) $source['run_id'])
                    ->where('term_id', $locked->term_id)
                    ->where('status', ScheduleGenerationRun::StatusUnderReview)
                    ->update([
                        'status' => ScheduleGenerationRun::StatusSuperseded,
                        'candidate_state' => 'Superseded',
                        'updated_at' => $timestamp,
                    ]);
            }

            DB::table('activity_log')->insert([
                'log_name' => 'scheduling',
                'description' => "Timetable candidate {$decision} by the Registrar.",
                'subject_type' => ScheduleGenerationRun::class,
                'subject_id' => $locked->id,
                'event' => mb_strtolower($decision) === 'accepted' ? 'candidate_accepted' : 'candidate_rejected',
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'properties' => json_encode([
                    'decision' => $decision,
                    'reason' => $reason,
                    'source_candidate_run_id' => $source['run_id'] ?? null,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return $locked->fresh();
        }, 3);
    }
}
