<?php

namespace App\Actions\Grades;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PostAndReleaseGradeRoster
{
    public function __construct(
        private readonly FinalResultPolicy $policy,
        private readonly OpenRegistrationImpactReviewsForGradeOutcome $impactReviews,
        private readonly AcademicRecordNotificationService $notifications,
        private readonly CompletionReadinessProjection $completionReadiness,
    ) {}

    public function execute(GradeRoster $roster, User $actor, string $authority = 'Registrar Post & Release'): GradeRoster
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff can post and release grade rosters.');
        }

        return DB::transaction(function () use ($roster, $actor, $authority): GradeRoster {
            $locked = GradeRoster::query()->with([
                'rows' => fn ($query) => $query->where('is_current_membership', true)->with('courseEnrollment.enrollment'),
                'teachingAssignment',
                'termOffering.term',
            ])->lockForUpdate()->findOrFail($roster->id);

            if ($locked->state === GradeRoster::StateReleased) {
                return $locked->fresh(['rows.outcomeEvents']);
            }

            if ($locked->state !== GradeRoster::StateSubmitted) {
                throw new RuntimeException('Only submitted rosters can be posted and released.');
            }

            $version = GradeRosterVersion::query()->with('rows')->where('grade_roster_id', $locked->id)
                ->where('version_number', $locked->current_version_number)->lockForUpdate()->firstOrFail();

            if ($version->state !== GradeRosterVersion::StateSubmitted
                || $version->membership_signature !== $locked->membership_signature
                || (int) $version->teaching_assignment_id !== (int) $locked->teaching_assignment_id
                || $version->rows->count() !== $locked->rows->count()) {
                throw new RuntimeException('The submitted roster is stale; synchronize and resubmit before release.');
            }

            $affectedStudents = collect();
            foreach ($version->rows as $versionRow) {
                $row = $locked->rows->firstWhere('id', $versionRow->grade_roster_row_id);

                if ($row === null || (int) $row->row_revision !== (int) $versionRow->row_revision) {
                    throw new RuntimeException('A roster row changed after submission; resubmission is required.');
                }

                $code = $this->policy->normalize($versionRow->final_result);
                $category = $this->policy->category($code);
                $value = $this->policy->numericValue($code);
                $termEndsOn = $locked->termOffering->term->ends_on;
                $deadline = $code === 'INC' ? $this->inclusiveYearDeadline($termEndsOn)->toDateString() : null;
                $sourceKey = "roster-version:{$version->id}:row:{$row->id}";

                $outcomeEvent = $row->outcomeEvents()->create([
                    'event_type' => GradeOutcomeEvent::TypeInitialRelease,
                    'result_code' => $code,
                    'source_term_ends_on' => $termEndsOn,
                    'inc_completion_note' => $versionRow->inc_completion_note,
                    'source_version_id' => $version->id,
                    'previous_value' => null,
                    'new_value' => $value,
                    'previous_category' => null,
                    'new_category' => $category,
                    'deadline' => $deadline,
                    'authority' => $authority,
                    'reason' => 'Initial registrar post and release.',
                    'recorded_by' => $actor->id,
                    'released_at' => now(),
                    'source_key' => $sourceKey,
                ]);

                $row->update([
                    'current_outcome_code' => $code,
                    'current_outcome_category' => $category,
                    'released_at' => now(),
                ]);

                $this->impactReviews->execute($row, $outcomeEvent, $actor);
                $this->notifications->recordAfterCommit($outcomeEvent, 'An official course result');
                $student = $row->courseEnrollment?->enrollment?->studentProfile;
                if ($student !== null) {
                    $affectedStudents->put($student->id, $student);
                }
            }

            $version->update([
                'state' => GradeRosterVersion::StateReleased,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'released_by' => $actor->id,
                'released_at' => now(),
            ]);

            $locked->update([
                'state' => GradeRoster::StateReleased,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'released_by' => $actor->id,
                'released_at' => now(),
            ]);

            $affectedStudents->each(fn ($student) => $this->completionReadiness->persist($student, $actor));

            return $locked->fresh(['rows.outcomeEvents']);
        }, attempts: 3);
    }

    private function inclusiveYearDeadline(Carbon $termEndsOn): Carbon
    {
        if ($termEndsOn->month === 2 && $termEndsOn->day === 29) {
            return $termEndsOn->copy()->addYear()->setDate($termEndsOn->year + 1, 2, 28);
        }

        return $termEndsOn->copy()->addYear();
    }
}
