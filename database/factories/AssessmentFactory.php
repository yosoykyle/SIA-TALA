<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\TermAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 */
class AssessmentFactory extends Factory
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
            'term_account_id' => TermAccount::factory(),
            'assessment_basis' => 'AuthorizedIndividualAssessment',
            'authority_reference' => 'SYNTH-ASSESSMENT-AUTHORITY',
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '1000.00',
            'discount_total' => '0.00',
            'total' => '1000.00',
            'required_downpayment' => '1000.00',
            'activated_by' => User::factory(),
            'activated_at' => now(),
        ];
    }
}
