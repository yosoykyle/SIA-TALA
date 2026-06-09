<?php

namespace Database\Factories;

use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacultyAvailabilitySubmission>
 */
class FacultyAvailabilitySubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $term = Term::factory();
        $period = FacultyAvailabilityPeriod::factory()->state([
            'term_id' => $term,
        ]);

        return [
            'term_id' => $term,
            'availability_period_id' => $period,
            'faculty_id' => User::factory(),
            'status' => FacultyAvailabilitySubmission::StatusSubmitted,
            'version' => 1,
            'submitted_at' => now(),
            'locked_at' => null,
            'parent_submission_id' => null,
            'change_reason' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }
}
