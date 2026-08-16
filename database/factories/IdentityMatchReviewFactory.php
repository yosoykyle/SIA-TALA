<?php

namespace Database\Factories;

use App\Models\AdmissionApplication;
use App\Models\IdentityMatchReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdentityMatchReview>
 */
class IdentityMatchReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_application_id' => AdmissionApplication::factory()->submitted(),
            'review_key' => 'identity-review:'.fake()->unique()->uuid(),
            'match_type' => IdentityMatchReview::TypeExactNameBirthDate,
            'outcome' => IdentityMatchReview::OutcomePending,
            'candidate_user_id' => null,
            'evidence_reference' => null,
            'corrected_identifier' => null,
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }
}
