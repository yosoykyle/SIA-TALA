<?php

namespace Database\Factories;

use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\TermOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationProposalItem>
 */
class RegistrationProposalItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_proposal_version_id' => RegistrationProposalVersion::factory(),
            'sequence' => 1,
            'term_offering_id' => TermOffering::factory(),
            'section_id' => Section::factory(),
            'units_snapshot' => '3.00',
            'course_code_snapshot' => strtoupper(fake()->bothify('SUBJ-###')),
            'course_title_snapshot' => fake()->words(4, true),
            'scheduling_treatment_snapshot' => 'Recurring',
            'contact_hours_snapshot' => ['lecture' => '3.00', 'laboratory' => '0.00'],
            'meeting_snapshot' => [],
        ];
    }
}
