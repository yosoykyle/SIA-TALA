<?php

namespace App\Actions\Grades;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Models\GradeOutcomeEvent;
use App\Models\IncDeadlineAmendment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AmendIncDeadline
{
    public function __construct(
        private readonly IncDeadlineService $deadlines,
        private readonly AcademicRecordNotificationService $notifications,
    ) {}

    public function execute(
        GradeOutcomeEvent $incomplete,
        Carbon $newDeadline,
        string $reason,
        User $registrar,
        ?string $expectedDeadline = null,
        ?int $expectedIncEventId = null,
        ?int $expectedAmendmentId = null,
    ): IncDeadlineAmendment {
        if (! $registrar->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can amend an INC deadline.');
        }

        if (blank(trim($reason))) {
            throw new RuntimeException('A reason for changing the INC deadline is required.');
        }

        if (mb_strlen(trim($reason)) < 10 || mb_strlen(trim($reason)) > 1000) {
            throw new RuntimeException('Deadline amendment reason must contain between 10 and 1000 characters.');
        }

        return DB::transaction(function () use ($incomplete, $newDeadline, $reason, $registrar, $expectedDeadline, $expectedIncEventId, $expectedAmendmentId): IncDeadlineAmendment {
            $incomplete = GradeOutcomeEvent::query()->lockForUpdate()->findOrFail($incomplete->id);

            $currentAmendmentId = IncDeadlineAmendment::query()
                ->where('grade_outcome_event_id', $incomplete->id)
                ->latest('recorded_at')->latest('id')->value('id');

            $existing = IncDeadlineAmendment::query()
                ->whereKey($currentAmendmentId)
                ->where('grade_outcome_event_id', $incomplete->id)
                ->whereDate('new_deadline', $newDeadline->toDateString())
                ->where('reason', trim($reason))
                ->where('recorded_by', $registrar->id)
                ->latest('id')->get()
                ->first(fn (IncDeadlineAmendment $amendment): bool => $expectedDeadline === null
                    || $amendment->previous_deadline->toDateString() === $expectedDeadline);

            if ($existing instanceof IncDeadlineAmendment) {
                return $existing;
            }

            if ($expectedIncEventId !== null && ($incomplete->id !== $expectedIncEventId
                || $currentAmendmentId !== $expectedAmendmentId)) {
                throw new RuntimeException('The INC deadline source changed. Reload and review the current result and deadline before recording an amendment.');
            }

            if ($this->deadlines->state($incomplete) === IncDeadlineService::StateResolved) {
                throw new RuntimeException('A resolved INC deadline cannot be amended.');
            }

            $current = $this->deadlines->currentDeadline($incomplete);

            if ($expectedDeadline !== null && $current->toDateString() !== $expectedDeadline) {
                throw new RuntimeException('The INC deadline changed. Reload and review the current deadline before recording an amendment.');
            }

            if ($newDeadline->toDateString() === $current->toDateString()) {
                throw new RuntimeException('The amended deadline must change the current deadline.');
            }

            $amendment = IncDeadlineAmendment::query()->create([
                'grade_outcome_event_id' => $incomplete->id,
                'previous_deadline' => $current,
                'new_deadline' => $newDeadline,
                'reason' => trim($reason),
                'recorded_by' => $registrar->id,
                'recorded_at' => now(),
            ]);

            $this->notifications->recordIncDeadlineAmendedAfterCommit($amendment);

            return $amendment;
        }, attempts: 3);
    }
}
