<?php

namespace Database\Factories;

use App\Models\RegistrationProposalConfirmation;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationProposalConfirmation>
 */
class RegistrationProposalConfirmationFactory extends Factory
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
            'method' => RegistrationProposalConfirmation::MethodSelfService,
            'learner_user_id' => User::factory(),
            'confirmed_at' => now(),
        ];
    }
}
