<?php

namespace Database\Factories;

use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChecklistItem> */
class ChecklistItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_type' => ChecklistItem::OwnerApplicant,
            'applicant_intake_id' => ApplicantIntake::factory(),
            'student_profile_id' => null,
            'source_policy_id' => AdmissionRequirementPolicy::factory(),
            'requirement_type' => strtoupper(fake()->words(2, true)),
            'blocking_level' => ChecklistItem::BlockingHandover,
            'status' => ChecklistItem::StatusPending,
            'evidence_method' => 'PHYSICAL_COPY',
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ];
    }

    public function forStudent(?StudentProfile $studentProfile = null): static
    {
        return $this->state(fn (): array => [
            'owner_type' => ChecklistItem::OwnerStudent,
            'applicant_intake_id' => null,
            'student_profile_id' => $studentProfile instanceof StudentProfile
                ? $studentProfile->id
                : StudentProfile::factory(),
        ]);
    }
}
