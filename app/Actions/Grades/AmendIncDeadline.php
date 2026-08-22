<?php

namespace App\Actions\Grades;

use App\Models\GradeOutcomeEvent;
use App\Models\IncDeadlineAmendment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AmendIncDeadline
{
    public function __construct(private readonly IncDeadlineService $deadlines) {}

    public function execute(
        GradeOutcomeEvent $incomplete,
        Carbon $newDeadline,
        string $authorityReference,
        Carbon $authorityDate,
        string $reason,
        User $registrar,
    ): IncDeadlineAmendment {
        if (! $registrar->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can amend an INC deadline.');
        }

        if (blank(trim($authorityReference)) || blank(trim($reason))) {
            throw new RuntimeException('Deadline amendment authority and reason are required.');
        }

        return DB::transaction(function () use ($incomplete, $newDeadline, $authorityReference, $authorityDate, $reason, $registrar): IncDeadlineAmendment {
            $incomplete = GradeOutcomeEvent::query()->lockForUpdate()->findOrFail($incomplete->id);

            if ($this->deadlines->state($incomplete) === IncDeadlineService::StateResolved) {
                throw new RuntimeException('A resolved INC deadline cannot be amended.');
            }

            $current = $this->deadlines->currentDeadline($incomplete);

            if ($newDeadline->toDateString() === $current->toDateString()) {
                throw new RuntimeException('The amended deadline must change the current deadline.');
            }

            return IncDeadlineAmendment::query()->create([
                'grade_outcome_event_id' => $incomplete->id,
                'previous_deadline' => $current,
                'new_deadline' => $newDeadline,
                'authority_reference' => trim($authorityReference),
                'authority_date' => $authorityDate,
                'reason' => trim($reason),
                'recorded_by' => $registrar->id,
                'recorded_at' => now(),
            ]);
        }, attempts: 3);
    }
}
