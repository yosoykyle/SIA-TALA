<?php

namespace App\Actions\Grades;

use App\Models\GradeOutcomeEvent;
use App\Models\IncDeadlineAmendment;
use Illuminate\Support\Carbon;
use RuntimeException;

class IncDeadlineService
{
    public const StateCompletionOpen = 'CompletionOpen';

    public const StateCompletionOverdue = 'CompletionOverdue';

    public const StateResolved = 'Resolved';

    public function originalDeadline(Carbon $termEndsOn): Carbon
    {
        if ($termEndsOn->month === 2 && $termEndsOn->day === 29) {
            return $termEndsOn->copy()->addYear()->setDate($termEndsOn->year + 1, 2, 28);
        }

        return $termEndsOn->copy()->addYear();
    }

    public function currentDeadline(GradeOutcomeEvent $incomplete): Carbon
    {
        if ($incomplete->result_code !== 'INC' || $incomplete->deadline === null) {
            throw new RuntimeException('The source event is not an unresolved INC result.');
        }

        $amended = IncDeadlineAmendment::query()
            ->where('grade_outcome_event_id', $incomplete->id)
            ->latest('recorded_at')
            ->first();

        $deadline = $amended instanceof IncDeadlineAmendment
            ? $amended->new_deadline
            : $incomplete->deadline;

        return $deadline->copy()->startOfDay();
    }

    public function state(GradeOutcomeEvent $incomplete, ?Carbon $at = null): string
    {
        $resolved = GradeOutcomeEvent::query()->where('predecessor_event_id', $incomplete->id)->exists();

        if ($resolved) {
            return self::StateResolved;
        }

        $at ??= now('Asia/Manila');

        return $at->toDateString() <= $this->currentDeadline($incomplete)->toDateString()
            ? self::StateCompletionOpen
            : self::StateCompletionOverdue;
    }
}
