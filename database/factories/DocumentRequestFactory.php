<?php

namespace Database\Factories;

use App\Models\DocumentRequest;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRequest>
 */
class DocumentRequestFactory extends Factory
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
            'term_id' => Term::factory(),
            'document_type' => 'certificate_of_enrollment',
            'status' => DocumentRequest::StatusPendingDocumentFee,
            'is_free_request' => false,
            'delivery_consent' => false,
            'delivery_mode' => DocumentRequest::DeliveryModePickup,
        ];
    }

    public function freePickup(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentRequest::StatusProcessing,
            'is_free_request' => true,
            'delivery_mode' => DocumentRequest::DeliveryModePickup,
        ]);
    }

    public function courier(): static
    {
        return $this->state(fn (): array => [
            'delivery_consent' => true,
            'delivery_mode' => DocumentRequest::DeliveryModeCourier,
        ]);
    }
}
