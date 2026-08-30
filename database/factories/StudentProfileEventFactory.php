<?php

namespace Database\Factories;

use App\Models\StudentProfile;
use App\Models\StudentProfileEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentProfileEvent>
 */
class StudentProfileEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'event_type' => StudentProfileEvent::TypeCorrection,
            'source' => 'RegistrarCorrection',
            'authority_reference' => 'SYNTH-PROFILE-CORRECTION',
            'reason' => 'Synthetic factual correction.',
            'before_snapshot' => ['phone' => '09000000000'],
            'after_snapshot' => ['phone' => '09111111111'],
            'changed_fields' => ['phone'],
            'actor_id' => User::factory(),
            'effective_at' => now(),
        ];
    }
}
