<?php

namespace Database\Factories;

use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationCorrectionItem>
 */
class ApplicationCorrectionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_correction_request_id' => ApplicationCorrectionRequest::factory(),
            'scope_type' => ApplicationCorrectionItem::ScopeField,
            'scope_key' => 'field:prior_school_name',
            'admission_requirement_id' => null,
        ];
    }
}
