<?php

namespace Database\Factories;

use App\Models\DegreeConferral;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TranscriptSnapshot>
 */
class TranscriptSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transcript_request_id' => TranscriptRequest::factory(),
            'degree_conferral_id' => DegreeConferral::factory(),
            'version' => 1,
            'reference' => 'TOR-'.fake()->unique()->numerify('########'),
            'template_version' => TranscriptRequest::TemplateServitechV1,
            'source_fingerprint' => hash('sha256', fake()->uuid()),
            'content' => [],
            'status' => TranscriptSnapshot::StatusIssued,
            'issued_by' => User::factory(),
            'issued_at' => now(),
        ];
    }
}
