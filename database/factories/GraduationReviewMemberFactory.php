<?php

namespace Database\Factories;

use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GraduationReviewMember> */
class GraduationReviewMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'graduation_review_batch_id' => GraduationReviewBatch::factory(),
            'student_profile_id' => StudentProfile::factory(),
            'added_by' => null,
            'added_at' => now(),
            'is_active' => true,
        ];
    }
}
