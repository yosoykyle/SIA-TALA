<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\CorVersion;
use App\Models\Enrollment;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorVersion>
 */
class CorVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'version' => 1,
            'registration_proposal_version_id' => RegistrationProposalVersion::factory(),
            'assessment_id' => Assessment::factory(),
            'published_timetable_version_id' => PublishedTimetableVersion::factory(),
            'snapshot' => ['courses' => [], 'fees' => [], 'source' => 'synthetic-canonical-factory'],
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'issued_by' => User::factory(),
            'issued_at' => now(),
        ];
    }
}
