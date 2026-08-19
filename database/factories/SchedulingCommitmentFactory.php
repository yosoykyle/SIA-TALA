<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\SchedulingCommitment;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulingCommitment>
 */
class SchedulingCommitmentFactory extends Factory
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
            'section_id' => Section::factory(),
            'faculty_user_id' => null,
            'room_id' => Room::factory(),
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'authority_reference' => 'SYNTH-COMMITMENT-001',
            'reason' => 'Synthetic externally approved scheduling commitment.',
            'recorded_by' => User::factory(),
        ];
    }
}
