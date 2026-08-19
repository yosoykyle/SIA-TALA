<?php

namespace Database\Factories;

use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublishedTimetableVersion>
 */
class PublishedTimetableVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'schedule_run_id' => fn (array $attributes): Factory => ScheduleGenerationRun::factory()
                ->state([
                    'term_id' => $attributes['term_id'],
                    'published_by' => $attributes['published_by'],
                    'published_at' => $attributes['published_at'],
                    'publication_version' => $attributes['version'],
                ]),
            'supersedes_version_id' => null,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'SYNTH-TIMETABLE-SIGNOFF-001',
            'publication_reason' => 'Synthetic accepted timetable publication.',
            'source_versions' => ['contract_version' => ScheduleGenerationRun::ContractVersion],
            'impact_summary' => [],
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'published_by' => User::factory(),
            'published_at' => now(),
        ];
    }
}
