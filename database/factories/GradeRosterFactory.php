<?php

namespace Database\Factories;

use App\Models\GradeRoster;
use App\Models\Section;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeRoster>
 */
class GradeRosterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'term_offering_id' => TermOffering::factory(),
            'section_id' => Section::factory(),
            'faculty_user_id' => User::factory(),
            'state' => GradeRoster::StateDraft,
            'grading_profile_snapshot' => config('grades.servitech_v1'),
            'submitted_by' => null,
            'submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'released_by' => null,
            'released_at' => null,
            'return_reason' => null,
        ];
    }
}
