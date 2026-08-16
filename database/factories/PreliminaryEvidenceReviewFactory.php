<?php

namespace Database\Factories;

use App\Models\DocumentEvidence;
use App\Models\PreliminaryEvidenceReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PreliminaryEvidenceReview>
 */
class PreliminaryEvidenceReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_evidence_id' => DocumentEvidence::factory(),
            'result' => PreliminaryEvidenceReview::ResultUnderReview,
            'reason' => null,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'supersedes_preliminary_evidence_review_id' => null,
        ];
    }
}
