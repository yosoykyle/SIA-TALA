<?php

namespace Database\Factories;

use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LateGradeAuthorization>
 */
class LateGradeAuthorizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grade_roster_id' => GradeRoster::factory(),
            'term_offering_id' => fn (array $attributes) => GradeRoster::find($attributes['grade_roster_id'])?->term_offering_id,
            'faculty_user_id' => fn (array $attributes) => GradeRoster::find($attributes['grade_roster_id'])?->faculty_user_id,
            'grading_period' => LateGradeAuthorization::PeriodFinal,
            'reason' => 'Approved late encoding window.',
            'approved_by' => User::factory(),
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addDay(),
            'state' => LateGradeAuthorization::StateActive,
        ];
    }
}
