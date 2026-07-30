<?php

namespace App\Actions\Grades;

use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use RuntimeException;

class SaveGradeRosterControlledOutcome
{
    public function __construct(
        private readonly GradeWindowService $windows,
        private readonly GradePolicyService $policy,
    ) {}

    public function execute(GradeRosterRow $row, ?string $code, User $actor): GradeRosterRow
    {
        $row->loadMissing('roster');

        if ((int) $row->roster->faculty_user_id !== (int) $actor->id) {
            throw new RuntimeException('Only the designated faculty member can encode this roster.');
        }

        if (! in_array($row->roster->state, [GradeRoster::StateDraft, GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true)) {
            throw new RuntimeException('Only draft, returned, or late-not-submitted rosters can be edited.');
        }

        if (! $this->windows->isOpen($row->roster, 'final')) {
            throw new RuntimeException('The grade encoding window is closed for the final period.');
        }

        if (blank($code)) {
            $row->current_outcome_code = null;
            $row->current_outcome_category = null;
        } else {
            $outcome = $this->policy->controlledOutcome($code);
            $row->final_equivalent = null;
            $row->computed_average = null;
            $row->current_outcome_code = $outcome['code'];
            $row->current_outcome_category = $outcome['category'];
        }

        $row->save();

        return $row->fresh();
    }
}
