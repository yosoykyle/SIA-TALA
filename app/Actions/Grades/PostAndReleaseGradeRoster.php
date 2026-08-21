<?php

namespace App\Actions\Grades;

use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PostAndReleaseGradeRoster
{
    public function __construct(
        private readonly GradePolicyService $policy,
        private readonly OpenRegistrationImpactReviewsForGradeOutcome $impactReviews,
    ) {}

    public function execute(GradeRoster $roster, User $actor, string $authority = 'Registrar Post & Release'): GradeRoster
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can post and release grade rosters.');
        }

        return DB::transaction(function () use ($roster, $actor, $authority): GradeRoster {
            $locked = GradeRoster::query()->with('rows.courseEnrollment.enrollment')->lockForUpdate()->findOrFail($roster->id);

            if ($locked->state !== GradeRoster::StateSubmitted) {
                throw new RuntimeException('Only submitted rosters can be posted and released.');
            }

            foreach ($locked->rows as $row) {
                if ($row->computed_average !== null) {
                    $outcome = $this->policy->outcomeForAverage($row->computed_average);
                } elseif (in_array($row->current_outcome_code, ['P', 'INC'], true)) {
                    $outcome = $this->policy->controlledOutcome((string) $row->current_outcome_code);
                } else {
                    throw new RuntimeException('Blank grade rows cannot be released.');
                }

                $deadline = $outcome['code'] === 'INC' ? $this->policy->incDeadline()->toDateString() : null;

                $outcomeEvent = $row->outcomeEvents()->create([
                    'event_type' => GradeOutcomeEvent::TypeInitialRelease,
                    'previous_value' => null,
                    'new_value' => $outcome['value'],
                    'previous_category' => null,
                    'new_category' => $outcome['category'],
                    'deadline' => $deadline,
                    'authority' => $authority,
                    'reason' => 'Initial registrar post and release.',
                    'recorded_by' => $actor->id,
                ]);

                $row->update([
                    'current_outcome_code' => $outcome['code'],
                    'current_outcome_category' => $outcome['category'],
                    'released_at' => now(),
                ]);

                $this->impactReviews->execute($row, $outcomeEvent, $actor);
            }

            $locked->update([
                'state' => GradeRoster::StateReleased,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'released_by' => $actor->id,
                'released_at' => now(),
            ]);

            return $locked->fresh(['rows.outcomeEvents']);
        });
    }
}
