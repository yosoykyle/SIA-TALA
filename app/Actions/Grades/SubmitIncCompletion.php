<?php

namespace App\Actions\Grades;

use App\Models\ClassOfferingTeachingAssignment;
use App\Models\GradeOutcomeEvent;
use App\Models\IncCompletionSubmission;
use App\Models\IncDeadlineAmendment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubmitIncCompletion
{
    public function __construct(private readonly IncDeadlineService $deadlines, private readonly FinalResultPolicy $policy) {}

    public function execute(GradeOutcomeEvent $incomplete, string $result, string $note, User $faculty): IncCompletionSubmission
    {
        return DB::transaction(function () use ($incomplete, $result, $note, $faculty): IncCompletionSubmission {
            $incomplete = GradeOutcomeEvent::query()->with('row.roster.teachingAssignment')->lockForUpdate()->findOrFail($incomplete->id);

            $controllingResult = GradeOutcomeEvent::query()
                ->where('grade_roster_row_id', $incomplete->grade_roster_row_id)
                ->latest('id')
                ->lockForUpdate()
                ->firstOrFail();
            $controllingAmendment = IncDeadlineAmendment::query()
                ->where('grade_outcome_event_id', $incomplete->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ((int) $controllingResult->id !== (int) $incomplete->id || $incomplete->result_code !== 'INC') {
                throw new RuntimeException('INC completion is stale because the released result has changed.');
            }

            if ($this->deadlines->state($incomplete) !== IncDeadlineService::StateCompletionOpen) {
                throw new RuntimeException('INC completion is not open. A valid future amendment is required to reopen it.');
            }

            $assignment = $incomplete->row->roster->teachingAssignment;

            if (! $assignment instanceof ClassOfferingTeachingAssignment
                || $assignment->state !== ClassOfferingTeachingAssignment::StateActive
                || (int) $assignment->faculty_user_id !== (int) $faculty->id) {
                throw new RuntimeException('Only the current designated Faculty can submit INC completion.');
            }

            $result = $this->policy->normalize($result);

            if ($result === 'INC' || blank(trim($note))) {
                throw new RuntimeException('INC completion requires a terminal final result and completion note.');
            }

            if (IncCompletionSubmission::query()->where('grade_outcome_event_id', $incomplete->id)
                ->where('state', IncCompletionSubmission::StateSubmitted)->exists()) {
                throw new RuntimeException('An INC completion is already awaiting Registrar review.');
            }

            return IncCompletionSubmission::query()->create([
                'grade_outcome_event_id' => $incomplete->id,
                'controlling_result_event_id' => $controllingResult->id,
                'controlling_deadline_amendment_id' => $controllingAmendment?->id,
                'controlling_deadline' => $this->deadlines->currentDeadline($incomplete)->toDateString(),
                'proposed_result' => $result,
                'completion_note' => trim($note),
                'state' => IncCompletionSubmission::StateSubmitted,
                'submitted_by' => $faculty->id,
                'submitted_at' => now(),
            ]);
        }, attempts: 3);
    }
}
