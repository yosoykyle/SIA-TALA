<?php

namespace Database\Factories;

use App\Models\ApprovedCoverage;
use App\Models\AssessmentObligation;
use App\Models\TermAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovedCoverage>
 */
class ApprovedCoverageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_account_id' => TermAccount::factory(),
            'assessment_obligation_id' => AssessmentObligation::factory(),
            'amount' => '1000.00',
            'authority_reference' => 'SYNTH-COVERAGE-AUTHORITY',
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ];
    }
}
