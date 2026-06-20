<?php

namespace Database\Factories;

use App\Models\ApplicantDocumentRequirement;
use App\Models\ApplicantIntake;
use App\Models\RetentionDocumentUndertaking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetentionDocumentUndertaking>
 */
class RetentionDocumentUndertakingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issuedAt = now();

        return [
            'applicant_intake_id' => ApplicantIntake::factory(),
            'applicant_document_requirement_id' => ApplicantDocumentRequirement::factory(),
            'student_profile_id' => null,
            'enrollment_id' => null,
            'status' => RetentionDocumentUndertaking::StatusActive,
            'issued_by' => null,
            'issued_at' => $issuedAt,
            'due_at' => $issuedAt->copy()->addDays(30),
            'extension_count' => 0,
            'meta' => [],
        ];
    }
}
