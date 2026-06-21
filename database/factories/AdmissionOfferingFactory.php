<?php

namespace Database\Factories;

use App\Models\AdmissionOffering;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionOffering>
 */
class AdmissionOfferingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'program_id' => null,
            'name' => 'Regular College Freshman',
            'entry_route' => AdmissionOffering::EntryRouteRegular,
            'prior_credential_pathway' => AdmissionOffering::PriorCredentialRegular,
            'citizenship_compliance_profile' => null,
            'year_level' => null,
            'status' => AdmissionOffering::StatusPublished,
            'published_at' => now(),
            'meta' => [],
        ];
    }
}
