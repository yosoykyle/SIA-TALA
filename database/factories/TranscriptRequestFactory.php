<?php

namespace Database\Factories;

use App\Models\DegreeConferral;
use App\Models\StudentProfile;
use App\Models\TranscriptRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TranscriptRequest>
 */
class TranscriptRequestFactory extends Factory
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
            'degree_conferral_id' => DegreeConferral::factory(),
            'version' => 1,
            'external_request_reference' => 'SYNTH-TOR-'.fake()->unique()->numerify('######'),
            'requested_on' => today(),
            'due_on' => today()->addDays(30),
            'template_version' => TranscriptRequest::TemplateServitechV1,
            'signatory_name' => fake()->name(),
            'signatory_title' => 'Registrar',
            'seal_input_type' => TranscriptRequest::SealPlacementInstruction,
            'seal_placement_instruction' => 'Apply the controlled institutional seal in the designated area.',
            'source_fingerprint' => hash('sha256', fake()->uuid()),
            'state' => TranscriptRequest::StateOpen,
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
