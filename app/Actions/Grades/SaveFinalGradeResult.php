<?php

namespace App\Actions\Grades;

use App\Models\ClassOfferingTeachingAssignment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaveFinalGradeResult
{
    public function __construct(private readonly FinalResultPolicy $policy, private readonly GradeWindowService $windows) {}

    public function execute(GradeRosterRow $row, string $result, ?string $incNote, User $actor): GradeRosterRow
    {
        return DB::transaction(function () use ($row, $result, $incNote, $actor): GradeRosterRow {
            $row = GradeRosterRow::query()->with('roster.teachingAssignment')->lockForUpdate()->findOrFail($row->id);
            $roster = $row->roster;
            $assignment = $roster->teachingAssignment;

            if (! $assignment instanceof ClassOfferingTeachingAssignment
                || $assignment->role !== ClassOfferingTeachingAssignment::RoleDesignated
                || $assignment->state !== ClassOfferingTeachingAssignment::StateActive
                || (int) $assignment->faculty_user_id !== (int) $actor->id) {
                throw new RuntimeException('Only the current designated Faculty can edit final results.');
            }

            if (! in_array($roster->state, [GradeRoster::StateDraft, GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true)) {
                throw new RuntimeException('This roster is locked against result changes.');
            }

            if (! $this->windows->isOpen($roster, 'final')) {
                throw new RuntimeException('The Grade Entry window is closed and no roster late authority is active.');
            }

            if ($roster->state === GradeRoster::StateReturned && $row->returned_at === null) {
                throw new RuntimeException('Only named returned rows may be changed.');
            }

            $normalized = $this->policy->normalize($result);
            $note = filled($incNote) ? trim((string) $incNote) : null;

            if ($normalized === 'INC' && blank($note)) {
                throw new RuntimeException('An INC completion note is required.');
            }

            $row->update([
                'final_result' => $normalized,
                'inc_completion_note' => $normalized === 'INC' ? $note : null,
                'row_revision' => $row->row_revision + 1,
                'returned_at' => null,
                'returned_by' => null,
                'return_reason' => null,
            ]);

            return $row->fresh();
        }, attempts: 3);
    }
}
