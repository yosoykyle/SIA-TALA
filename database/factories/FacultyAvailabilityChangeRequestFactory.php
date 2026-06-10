<?php

namespace Database\Factories;

use App\Models\FacultyAvailabilityChangeRequest;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacultyAvailabilityChangeRequest>
 */
class FacultyAvailabilityChangeRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $term = Term::factory();
        $faculty = User::factory();
        $submission = FacultyAvailabilitySubmission::factory()->state([
            'term_id' => $term,
            'faculty_id' => $faculty,
        ]);

        return [
            'term_id' => $term,
            'faculty_id' => $faculty,
            'submission_id' => $submission,
            'status' => FacultyAvailabilityChangeRequest::StatusPending,
            'reason' => 'Updated teaching availability.',
            'source_windows' => [[
                'day_of_week' => 1,
                'starts_at' => '08:00:00',
                'ends_at' => '12:00:00',
                'notes' => null,
            ]],
            'requested_windows' => [[
                'day_of_week' => 2,
                'starts_at' => '09:00:00',
                'ends_at' => '12:00:00',
                'notes' => null,
            ]],
            'requested_by' => $faculty,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'creates_submission_id' => null,
        ];
    }
}
