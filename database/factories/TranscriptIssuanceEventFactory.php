<?php

namespace Database\Factories;

use App\Models\TranscriptIssuanceEvent;
use App\Models\TranscriptRequest;
use App\Models\TranscriptSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TranscriptIssuanceEvent>
 */
class TranscriptIssuanceEventFactory extends Factory
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
            'transcript_snapshot_id' => TranscriptSnapshot::factory(),
            'type' => TranscriptIssuanceEvent::TypeIssued,
            'reference' => 'TOR-EVENT-'.fake()->unique()->numerify('########'),
            'authority_reference' => 'SYNTH-TOR-AUTHORITY',
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
