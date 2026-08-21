<?php

namespace Database\Factories;

use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationProposalVersion>
 */
class RegistrationProposalVersionFactory extends Factory
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
            'state' => RegistrationProposalVersion::StateDraft,
            'purpose' => RegistrationProposalVersion::PurposeInitial,
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
            'published_timetable_version_id' => PublishedTimetableVersion::factory(),
            'curriculum_version_id' => CurriculumVersion::factory(),
            'source_snapshot' => ['source' => 'synthetic-canonical-factory'],
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'prepared_by' => User::factory(),
            'prepared_at' => now(),
        ];
    }
}
