<?php

namespace Database\Factories;

use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Models\GraduationReviewMember;
use App\Models\GraduationSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GraduationSnapshot> */
class GraduationSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'graduation_review_member_id' => GraduationReviewMember::factory(),
            'version' => 1,
            'result_status' => GraduationEligibilitySnapshotService::ResultReadyForRegistrarReview,
            'evaluation_snapshot' => [
                'student' => [],
                'program' => [],
                'curriculum_version' => [],
                'generated' => [],
                'result_status' => GraduationEligibilitySnapshotService::ResultReadyForRegistrarReview,
                'blocker_groups' => [],
                'completed_requirements' => [],
                'current_enrollments' => [],
                'missing_requirements' => [],
                'failed_requirements' => [],
                'pending_grade_requirements' => [],
                'inc_requirements' => [],
                'withdrawn_or_dropped_requirements' => [],
                'accepted_credits' => [],
                'approved_exceptions' => [],
                'active_holds' => [],
                'clearance_blockers' => [],
                'remaining_units' => 0,
                'source_references' => [],
                'student_projection' => [],
            ],
            'generated_by' => null,
            'generated_at' => now(),
            'made_visible_by' => null,
            'made_visible_at' => null,
            'visibility_reason' => null,
        ];
    }
}
