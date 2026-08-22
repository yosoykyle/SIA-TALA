<?php

namespace App\Actions\Grades;

use App\Models\GradeRoster;
use App\Models\GradeRosterVersion;
use App\Models\GradeRosterVersionRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubmitGradeRoster
{
    public function __construct(private readonly GradeWindowService $windows, private readonly FinalResultPolicy $policy) {}

    public function execute(GradeRoster $roster, User $actor): GradeRoster
    {
        return DB::transaction(function () use ($roster, $actor): GradeRoster {
            $locked = GradeRoster::query()->with(['rows' => fn ($query) => $query->where('is_current_membership', true), 'teachingAssignment'])
                ->lockForUpdate()->findOrFail($roster->id);

            if ((int) $locked->teachingAssignment?->faculty_user_id !== (int) $actor->id) {
                throw new RuntimeException('Only the designated faculty member can submit this roster.');
            }

            if (! in_array($locked->state, [GradeRoster::StateDraft, GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true)) {
                throw new RuntimeException('This roster cannot be submitted from its current state.');
            }

            if ($locked->rows->isEmpty()) {
                throw new RuntimeException('A roster cannot be submitted without grade rows.');
            }

            if (! $this->windows->isOpen($locked, 'final')) {
                throw new RuntimeException('The Grade Entry window is closed and no roster late authority is active.');
            }

            foreach ($locked->rows as $row) {
                if ($row->final_result === null) {
                    throw new RuntimeException('Every current roster row requires one final result before submission.');
                }

                $this->policy->normalize($row->final_result);
            }

            $versionNumber = $locked->current_version_number + 1;
            $version = GradeRosterVersion::query()->create([
                'grade_roster_id' => $locked->id,
                'version_number' => $versionNumber,
                'teaching_assignment_id' => $locked->teaching_assignment_id,
                'membership_signature' => $locked->membership_signature,
                'state' => GradeRosterVersion::StateSubmitted,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
            ]);

            foreach ($locked->rows as $row) {
                GradeRosterVersionRow::query()->create([
                    'grade_roster_version_id' => $version->id,
                    'grade_roster_row_id' => $row->id,
                    'course_enrollment_id' => $row->course_enrollment_id,
                    'final_result' => $row->final_result,
                    'inc_completion_note' => $row->inc_completion_note,
                    'row_revision' => $row->row_revision,
                ]);
            }

            $locked->update([
                'state' => GradeRoster::StateSubmitted,
                'current_version_number' => $versionNumber,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'return_reason' => null,
            ]);

            return $locked->fresh(['rows', 'versions.rows']);
        }, attempts: 3);
    }
}
