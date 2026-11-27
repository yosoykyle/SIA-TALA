<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TAL96D5BSharedFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_staff_sidebar_groups_registrar_destinations_by_purpose(): void
    {
        $this->seed(DatabaseSeeder::class);

        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $groupLabels = collect(Filament::getPanel('admin')->buildNavigation())
            ->map(fn ($group): ?string => $group->getLabel())
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['Learner work', 'Academic setup'], $groupLabels);
    }
}
