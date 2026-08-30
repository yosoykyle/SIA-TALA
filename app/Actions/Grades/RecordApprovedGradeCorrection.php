<?php

namespace App\Actions\Grades;

use App\Actions\Academics\AcademicRecordNotificationService;
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
    ) {}

    public function execute(GradeRosterRow $row, string $correctedCode, string $authority, string $reason, ?string $evidenceReference, User $actor, ?string $commandKey = null): GradeRosterRow
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can record posted grade corrections.');
        }

        return DB::transaction(function () use ($row, $correctedCode, $authority, $reason, $evidenceReference, $actor, $commandKey): GradeRosterRow {
            $locked = GradeRosterRow::query()->lockForUpdate()->findOrFail($row->id);

            if ($locked->released_at === null) {
                throw new RuntimeException('Only released rows can receive posted corrections.');
            }

            $code = $this->policy->normalize($correctedCode);
            $predecessor = $locked->outcomeEvents()->latest('id')->firstOrFail();
            $termEndsOn = $predecessor->source_term_ends_on ?? $locked->roster->termOffering->term->ends_on;
            $sourceKey = 'grade-correction:'.hash('sha256', $commandKey ?? implode('|', [
                $locked->id, $code, trim($authority), trim($reason), (string) $evidenceReference,
            ]));

            $existing = $locked->outcomeEvents()->where('source_key', $sourceKey)->first();

            if ($existing instanceof GradeOutcomeEvent) {
                return $locked->fresh('outcomeEvents');
            }

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
                'authority' => $authority,
                'reason' => $reason,
                'evidence_reference' => $evidenceReference,
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
                $this->transcriptSupersession->execute($student, $actor, $authority, $reason);
            }

            return $locked->fresh('outcomeEvents');
        }, attempts: 3);
    }
}
