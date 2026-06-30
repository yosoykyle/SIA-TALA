<?php

namespace App\Actions\Grades;

use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use RuntimeException;

class SaveGradeRosterPeriodEquivalent
{
    public function __construct(
        private readonly GradeWindowService $windows,
        private readonly GradePolicyService $policy,
    ) {}

    public function execute(GradeRosterRow $row, string $period, float|int|string|null $value, User $actor): GradeRosterRow
    {
        $row->loadMissing('roster');
        $period = strtolower($period);
        $column = match ($period) {
            'prelim' => 'prelim_equivalent',
            'midterm' => 'midterm_equivalent',
            'final' => 'final_equivalent',
            default => throw new RuntimeException('Invalid grading period.'),
        };

        if ((int) $row->roster->faculty_user_id !== (int) $actor->id) {
            throw new RuntimeException('Only the designated faculty member can encode this roster.');
        }

        if (! in_array($row->roster->state, [GradeRoster::StateDraft, GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true)) {
            throw new RuntimeException('Only draft, returned, or late-not-submitted rosters can be edited.');
        }

        if (! $this->windows->isOpen($row->roster, $period)) {
            throw new RuntimeException('The grade encoding window is closed for this period.');
        }

        if ($value !== null && ((float) $value < 0 || (float) $value > 100)) {
            throw new RuntimeException('Period equivalent must be between 0 and 100.');
        }

        $row->{$column} = $value;

        if ($row->prelim_equivalent !== null && $row->midterm_equivalent !== null && $row->final_equivalent !== null) {
            $row->computed_average = $this->policy->computedAverage($row->prelim_equivalent, $row->midterm_equivalent, $row->final_equivalent);
        } else {
            $row->computed_average = null;
        }

        $row->save();

        return $row->fresh();
    }
}
