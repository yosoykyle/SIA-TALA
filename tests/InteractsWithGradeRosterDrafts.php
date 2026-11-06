<?php

namespace Tests;

use App\Actions\Grades\SaveGradeRosterDraft;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\User;

trait InteractsWithGradeRosterDrafts
{
    /** @param array<int, array{final_result: string, inc_completion_note: ?string}> $results */
    private function saveGradeRosterDraft(GradeRoster $roster, User $faculty, array $results): GradeRoster
    {
        $roster = $roster->fresh();
        $rows = $roster->rows()->where('is_current_membership', true)->orderBy('id')->get()
            ->map(fn (GradeRosterRow $row): array => array_replace(
                $row->only(['id', 'final_result', 'inc_completion_note']),
                $results[$row->id] ?? [],
            ))->all();

        return app(SaveGradeRosterDraft::class)->execute(
            $roster,
            $rows,
            $roster->lock_version,
            $roster->membership_signature,
            $faculty,
        );
    }
}
