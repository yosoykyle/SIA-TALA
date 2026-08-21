<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\TermAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermAccount>
 */
class TermAccountFactory extends Factory
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
            'credential_user_id' => fn (array $attributes): int => (int) Enrollment::query()->findOrFail($attributes['enrollment_id'])->credential_user_id,
            'term_id' => fn (array $attributes): int => (int) Enrollment::query()->findOrFail($attributes['enrollment_id'])->term_id,
            'state' => TermAccount::StateOpen,
        ];
    }
}
