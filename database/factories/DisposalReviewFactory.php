<?php

namespace Database\Factories;

use App\Enums\RetentionCategory;
use App\Models\DisposalReview;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DisposalReview>
 */
class DisposalReviewFactory extends Factory
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
            'retention_category' => RetentionCategory::ShortOperational->value,
            'hold_check_result' => false,
            'legal_audit_attestation' => true,
            'decision' => DisposalReview::DecisionClearedForDisposal,
            'reason' => fake()->sentence(),
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ];
    }

    public function blockedByHold(): static
    {
        return $this->state([
            'hold_check_result' => true,
            'legal_audit_attestation' => false,
            'decision' => DisposalReview::DecisionBlockedByHold,
        ]);
    }
}
