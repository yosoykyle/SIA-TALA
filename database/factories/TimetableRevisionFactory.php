<?php

namespace Database\Factories;

use App\Models\PublishedTimetableVersion;
use App\Models\TimetableRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimetableRevision>
 */
class TimetableRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_version_id' => PublishedTimetableVersion::factory(),
            'term_id' => fn (array $attributes): int => (int) PublishedTimetableVersion::query()->findOrFail($attributes['source_version_id'])->term_id,
            'state' => TimetableRevision::StateDraft,
            'change_type' => 'Time',
            'changes_snapshot' => [],
            'impact_snapshot' => ['affected_registration_case_ids' => []],
            'content_hash' => hash('sha256', fake()->uuid()),
            'authority_reference' => fake()->bothify('SYN-REV-####'),
            'reason' => 'Synthetic prepared timetable revision.',
            'prepared_by' => User::factory(),
            'prepared_at' => now(),
        ];
    }
}
