<?php

namespace Database\Factories;

use App\Models\FacultyAvailabilityDeclaration;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<FacultyAvailabilityDeclaration>
 */
class FacultyAvailabilityDeclarationFactory extends Factory
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
            'faculty_user_id' => User::factory(),
            'version' => 1,
            'declaration' => 'Available',
            'hard_unavailability' => [],
            'correction_reason' => null,
            'declared_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (FacultyAvailabilityDeclaration $declaration): void {
            $faculty = User::query()->findOrFail($declaration->faculty_user_id);
            Role::findOrCreate(User::StaffRoleFaculty);
            $faculty->syncRoles([User::StaffRoleFaculty]);
        });
    }
}
