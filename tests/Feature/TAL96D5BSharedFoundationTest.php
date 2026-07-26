<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TAL96D5BSharedFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_utc_storage_uses_one_philippine_display_timezone_contract(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Manila', config('app.display_timezone'));
        $this->assertSame('Asia/Manila', FilamentTimezone::get());

        $timestamp = CarbonImmutable::parse('2026-07-26 00:00:00', 'UTC');

        $this->assertSame(
            'July 26, 2026 at 8:00 AM',
            DisplayDateTime::format($timestamp, 'F j, Y \a\t g:i A'),
        );
    }

    public function test_staff_sidebar_uses_ordered_workflow_groups_instead_of_role_names(): void
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

        $this->assertSame([
            'Admissions',
            'Academic Setup',
            'Offerings & Scheduling',
            'Enrollment',
            'Finance',
            'Grades',
            'Student Records',
            'Reports & Audit',
        ], $groupLabels);

        $this->assertEmpty(array_intersect(
            ['Registrar', 'Accounting', 'Faculty', 'Academic Head', 'System Administration'],
            $groupLabels,
        ));
    }
}
