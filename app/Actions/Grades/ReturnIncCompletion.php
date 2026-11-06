<?php

namespace App\Actions\Grades;

use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReturnIncCompletion
{
    public function execute(IncCompletionSubmission $submission, User $registrar, string $reason): IncCompletionSubmission
    {
        if ($registrar->status !== User::StatusActive || ! $registrar->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only active Registrar staff can return an INC completion.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new RuntimeException('An INC completion return reason between 10 and 1000 characters is required.');
        }

        return DB::transaction(function () use ($submission, $registrar, $reason): IncCompletionSubmission {
            $locked = IncCompletionSubmission::query()->lockForUpdate()->findOrFail($submission->id);

            if ($locked->state === IncCompletionSubmission::StateReturned) {
                return $locked;
            }

            if ($locked->state !== IncCompletionSubmission::StateSubmitted) {
                throw new RuntimeException('Only a submitted INC completion can be returned.');
            }

            $source = GradeOutcomeEvent::query()->findOrFail($locked->grade_outcome_event_id);
            GradeRosterRow::query()->lockForUpdate()->findOrFail($source->grade_roster_row_id);
            $source = GradeOutcomeEvent::query()->lockForUpdate()->findOrFail($source->id);

            if ($source->result_code !== 'INC' || $source->released_at === null) {
                throw new RuntimeException('The completion must belong to a released INC result.');
            }

            $locked->update([
                'state' => IncCompletionSubmission::StateReturned,
                'reviewed_by' => $registrar->id,
                'reviewed_at' => now(),
                'return_reason' => $reason,
            ]);

            return $locked->fresh();
        }, attempts: 3);
    }
}
