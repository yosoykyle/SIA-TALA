<?php

namespace App\Actions\Grades;

use App\Models\GradeRoster;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubmitGradeRoster
{
    public function execute(GradeRoster $roster, User $actor): GradeRoster
    {
        return DB::transaction(function () use ($roster, $actor): GradeRoster {
            $locked = GradeRoster::query()->with('rows')->lockForUpdate()->findOrFail($roster->id);

            if ((int) $locked->faculty_user_id !== (int) $actor->id) {
                throw new RuntimeException('Only the designated faculty member can submit this roster.');
            }

            if (! in_array($locked->state, [GradeRoster::StateDraft, GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true)) {
                throw new RuntimeException('This roster cannot be submitted from its current state.');
            }

            if ($locked->rows->isEmpty()) {
                throw new RuntimeException('A roster cannot be submitted without grade rows.');
            }

            foreach ($locked->rows as $row) {
                if ($row->computed_average === null && ! in_array($row->current_outcome_code, ['P', 'INC'], true)) {
                    throw new RuntimeException('Every row must have complete period equivalents, P, or INC before submission.');
                }
            }

            $locked->update([
                'state' => GradeRoster::StateSubmitted,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'return_reason' => null,
            ]);

            return $locked->fresh(['rows']);
        });
    }
}
