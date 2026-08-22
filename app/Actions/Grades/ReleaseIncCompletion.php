<?php

namespace App\Actions\Grades;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReleaseIncCompletion
{
    public function __construct(
        private readonly FinalResultPolicy $policy,
        private readonly OpenRegistrationImpactReviewsForGradeOutcome $impactReviews,
        private readonly AcademicRecordNotificationService $notifications,
    ) {}

    public function execute(IncCompletionSubmission $submission, User $registrar, string $authorityReference): GradeOutcomeEvent
    {
        if (! $registrar->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can release an INC completion.');
        }

        return DB::transaction(function () use ($submission, $registrar, $authorityReference): GradeOutcomeEvent {
            $submission = IncCompletionSubmission::query()->with('incompleteEvent.row')->lockForUpdate()->findOrFail($submission->id);

            if ($submission->state === IncCompletionSubmission::StateReleased && $submission->released_event_id !== null) {
                return GradeOutcomeEvent::query()->findOrFail($submission->released_event_id);
            }

            if ($submission->state !== IncCompletionSubmission::StateSubmitted) {
                throw new RuntimeException('Only a submitted INC completion can be released.');
            }

            $incomplete = $submission->incompleteEvent;
            $row = GradeRosterRow::query()->lockForUpdate()->findOrFail($incomplete->grade_roster_row_id);
            $code = $this->policy->normalize($submission->proposed_result);
            $sourceKey = "inc-completion:{$submission->id}";
            $event = GradeOutcomeEvent::query()->firstOrCreate(['source_key' => $sourceKey], [
                'grade_roster_row_id' => $row->id,
                'event_type' => GradeOutcomeEvent::TypeIncResolution,
                'result_code' => $code,
                'source_term_ends_on' => $incomplete->source_term_ends_on,
                'predecessor_event_id' => $incomplete->id,
                'previous_value' => null,
                'new_value' => $this->policy->numericValue($code),
                'previous_category' => GradeRosterRow::CategoryIncomplete,
                'new_category' => $this->policy->category($code),
                'authority' => trim($authorityReference),
                'reason' => $submission->completion_note,
                'recorded_by' => $registrar->id,
                'released_at' => now(),
            ]);

            $row->update([
                'current_outcome_code' => $code,
                'current_outcome_category' => $this->policy->category($code),
            ]);
            $submission->update([
                'state' => IncCompletionSubmission::StateReleased,
                'released_event_id' => $event->id,
                'reviewed_by' => $registrar->id,
                'reviewed_at' => now(),
            ]);
            $this->impactReviews->execute($row, $event, $registrar);
            $this->notifications->recordAfterCommit($event, 'An INC completion result');

            return $event;
        }, attempts: 3);
    }
}
