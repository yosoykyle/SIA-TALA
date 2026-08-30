<?php

namespace App\Actions\Grades;

use App\Models\ClassOfferingTeachingAssignment;
use App\Models\GradeRoster;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaveGradeRosterDraft
{
    public function __construct(
        private readonly FinalResultPolicy $policy,
        private readonly GradeWindowService $windows,
    ) {}

    /**
     * @param  list<array{id: int, final_result: string|null, inc_completion_note: string|null}>  $rows
     */
    public function execute(
        GradeRoster $roster,
        array $rows,
        int $expectedLockVersion,
        ?string $expectedMembershipSignature,
        User $actor,
    ): GradeRoster {
        return DB::transaction(function () use ($roster, $rows, $expectedLockVersion, $expectedMembershipSignature, $actor): GradeRoster {
            $locked = GradeRoster::query()
                ->with(['rows' => fn ($query) => $query->where('is_current_membership', true)->orderBy('id'), 'teachingAssignment'])
                ->lockForUpdate()
                ->findOrFail($roster->id);
            $assignment = $locked->teachingAssignment;

            if (! $assignment instanceof ClassOfferingTeachingAssignment
                || $assignment->role !== ClassOfferingTeachingAssignment::RoleDesignated
                || $assignment->state !== ClassOfferingTeachingAssignment::StateActive
                || (int) $assignment->faculty_user_id !== (int) $actor->id) {
                throw new RuntimeException('Only the current designated Faculty can save this roster draft.');
            }

            if (! in_array($locked->state, [GradeRoster::StateDraft, GradeRoster::StateReturned, GradeRoster::StateLateNotSubmitted], true)) {
                throw new RuntimeException('This roster is locked against draft changes.');
            }

            if (! $this->windows->isOpen($locked, 'final')) {
                throw new RuntimeException('The Grade Entry window is closed and no roster late authority is active.');
            }

            if ((int) $locked->lock_version !== $expectedLockVersion
                || $locked->membership_signature !== $expectedMembershipSignature) {
                throw new RuntimeException('The roster changed while this page was open. Refresh and review the current membership before saving.');
            }

            $submittedRows = collect($rows)->keyBy(fn (array $row): int => (int) $row['id']);

            if ($submittedRows->keys()->sort()->values()->all() !== $locked->rows->pluck('id')->sort()->values()->all()) {
                throw new RuntimeException('The official roster membership changed. Refresh before saving.');
            }

            foreach ($locked->rows as $row) {
                $submitted = $submittedRows->get($row->id);
                $result = filled($submitted['final_result'] ?? null)
                    ? $this->policy->normalize((string) $submitted['final_result'])
                    : null;
                $note = filled($submitted['inc_completion_note'] ?? null)
                    ? trim((string) $submitted['inc_completion_note'])
                    : null;

                if ($result === 'INC' && blank($note)) {
                    throw new RuntimeException('Each INC result requires a safe completion note.');
                }

                $note = $result === 'INC' ? $note : null;
                $changed = $row->final_result !== $result || $row->inc_completion_note !== $note;

                if ($locked->state === GradeRoster::StateReturned && $row->returned_at === null && $changed) {
                    throw new RuntimeException('Only named returned rows may be changed.');
                }

                if ($changed) {
                    $row->update([
                        'final_result' => $result,
                        'inc_completion_note' => $note,
                        'row_revision' => $row->row_revision + 1,
                        'returned_at' => null,
                        'returned_by' => null,
                        'return_reason' => null,
                    ]);
                }
            }

            $locked->update(['lock_version' => $locked->lock_version + 1]);

            return $locked->fresh(['rows.courseEnrollment.enrollment.studentProfile', 'teachingAssignment']);
        }, attempts: 3);
    }
}
