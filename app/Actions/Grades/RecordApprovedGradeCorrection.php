<?php

namespace App\Actions\Grades;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\Completion\SupersedeTranscriptSnapshots;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecordApprovedGradeCorrection
{
    public function __construct(
        private readonly FinalResultPolicy $policy,
        private readonly IncDeadlineService $deadlines,
        private readonly OpenRegistrationImpactReviewsForGradeOutcome $impactReviews,
        private readonly AcademicRecordNotificationService $notifications,
        private readonly SupersedeTranscriptSnapshots $transcriptSupersession,
        private readonly CompletionReadinessProjection $completionReadiness,
    ) {}

    public function execute(GradeRosterRow $row, string $correctedCode, string $reason, User $actor, ?string $commandKey = null, ?int $expectedEventId = null): GradeRosterRow
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can record posted grade corrections.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A reason for the grade correction is required.');
        }

        return DB::transaction(function () use ($row, $correctedCode, $reason, $actor, $commandKey, $expectedEventId): GradeRosterRow {
            $locked = GradeRosterRow::query()->lockForUpdate()->findOrFail($row->id);

            if ($locked->released_at === null) {
                throw new RuntimeException('Only released rows can receive posted corrections.');
            }

            $code = $this->policy->normalize($correctedCode);
            $sourceKey = 'grade-correction:'.hash('sha256', $commandKey ?? implode('|', [
                $locked->id, $code, $reason,
            ]));

            $existing = $locked->outcomeEvents()->where('source_key', $sourceKey)->first();

            if ($existing instanceof GradeOutcomeEvent) {
                return $locked->fresh('outcomeEvents');
            }

            $predecessor = $locked->outcomeEvents()->whereNotNull('released_at')->latest('id')->lockForUpdate()->firstOrFail();

            if ($expectedEventId !== null && (int) $predecessor->id !== $expectedEventId) {
                throw new RuntimeException('The released result changed. Reload and review the current result before recording a correction.');
            }

            if ($code === $this->policy->normalize($predecessor->result_code)) {
                throw new RuntimeException('Choose a different final result. The selected result is already the official result.');
            }

            $termEndsOn = $predecessor->source_term_ends_on ?? $locked->roster->termOffering->term->ends_on;
            $event = $locked->outcomeEvents()->create([
                'source_key' => $sourceKey,
                'event_type' => GradeOutcomeEvent::TypePostedCorrection,
                'result_code' => $code,
                'source_term_ends_on' => $termEndsOn,
                'predecessor_event_id' => $predecessor->id,
                'previous_value' => is_numeric($locked->current_outcome_code) ? (float) $locked->current_outcome_code : null,
                'new_value' => $this->policy->numericValue($code),
                'previous_category' => $locked->current_outcome_category,
                'new_category' => $this->policy->category($code),
                'deadline' => $code === 'INC' ? $this->deadlines->originalDeadline($termEndsOn)->toDateString() : null,
                'reason' => $reason,
                'recorded_by' => $actor->id,
                'released_at' => now(),
            ]);

            $locked->update([
                'current_outcome_code' => $code,
                'current_outcome_category' => $this->policy->category($code),
            ]);
            $this->impactReviews->execute($locked, $event, $actor);
            $this->notifications->recordAfterCommit($event, 'An authorized academic result correction');
            $student = $locked->courseEnrollment?->enrollment?->studentProfile;
            if ($student !== null) {
                $this->transcriptSupersession->execute($student, $actor, '', $reason);
                $this->completionReadiness->persist($student, $actor);
            }

            return $locked->fresh('outcomeEvents');
        }, attempts: 3);
    }
}
