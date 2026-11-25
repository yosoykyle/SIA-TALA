<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\IrregularEnrollmentRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IrregularEnrollmentRequest>
 */
class IrregularEnrollmentRequestFactory extends Factory
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
            'student_profile_id' => fn (array $attributes): int => (int) Enrollment::query()->findOrFail($attributes['enrollment_id'])->student_profile_id,
            'state' => IrregularEnrollmentRequest::StateSubmitted,
            'reasons' => [['code' => 'failed', 'label' => 'Failed subject', 'details' => 'You did not pass IT-101A.', 'subjects' => ['IT-101A']]],
            'student_remarks' => null,
            'supporting_documents' => null,
            'section_ids' => [],
            'selected_units' => 0,
            'submitted_at' => now(),
        ];
    }

    public function returned(string $reason = 'Please add the missing retake.'): static
    {
        return $this->state(fn (): array => [
            'state' => IrregularEnrollmentRequest::StateReturned,
            'returned_at' => now(),
            'return_reason' => $reason,
        ]);
    }
}
