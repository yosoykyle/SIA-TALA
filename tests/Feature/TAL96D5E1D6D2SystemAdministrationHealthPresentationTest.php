<?php

namespace Tests\Feature;

use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Actions\SystemAdministration\SystemHealthPresenter;
use App\Filament\Pages\GovernanceAudit;
use App\Filament\Pages\SystemHealth;
use App\Filament\Widgets\StaffRoleWorkspaceOverviewWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1D6D2SystemAdministrationHealthPresentationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function system_administration_home_links_to_the_two_canonical_destinations(): void
    {
        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));

        Livewire::test(StaffRoleWorkspaceOverviewWidget::class)
            ->assertSee('System Health')
            ->assertSee('Governance & Audit')
            ->assertSee('unknown');

        Livewire::test(SystemHealth::class)
            ->assertOk()
            ->assertSee('Prospective RPO target')
            ->assertSee('Prospective RTO target');

        Livewire::test(GovernanceAudit::class)
            ->assertOk()
            ->call('setActiveTab', GovernanceEvidenceProjection::PrivacyRetention)
            ->assertSee('Not evaluated by TALA');
    }

    #[Test]
    public function system_health_and_governance_are_system_super_admin_only(): void
    {
        $this->actingAs($this->staff(User::StaffRoleRegistrar));
        $this->assertFalse(SystemHealth::canAccess());
        $this->assertFalse(GovernanceAudit::canAccess());

        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));
        $this->assertTrue(SystemHealth::canAccess());
        $this->assertTrue(GovernanceAudit::canAccess());
        $this->assertNotEmpty(app(SystemHealthPresenter::class)->capture()['rows']);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
