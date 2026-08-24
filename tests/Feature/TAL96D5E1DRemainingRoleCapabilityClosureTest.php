<?php

namespace Tests\Feature;

use App\Filament\Pages\GovernanceAudit;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1DRemainingRoleCapabilityClosureTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function system_administrator_has_exactly_the_five_canonical_navigation_destinations(): void
    {
        $role = Role::query()->firstOrCreate(['name' => User::StaffRoleSystemSuperAdmin, 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findOrCreate('manage-faqs', 'web'));
        $admin = User::factory()->create(['status' => User::StatusActive]);
        $admin->assignRole(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $items = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems());
        $labels = $items->map(fn ($item): string => $item->getLabel())->values()->all();

        $this->assertSame([
            'Home',
            'Users & Access',
            'Public Content',
            'System Health',
            'Governance & Audit',
        ], $labels);
        $this->assertTrue(UserResource::canAccess());
        $this->assertTrue(FaqEntryResource::canAccess());
        $this->assertTrue(SystemHealth::canAccess());
        $this->assertTrue(GovernanceAudit::canAccess());

        foreach ([
            'Reports',
            'Settings',
            'Integration Status',
            'Operational Events',
            'Audit Logs',
            'Disposal Review',
        ] as $retiredLabel) {
            $this->assertNotContains($retiredLabel, $labels);
        }
    }
}
