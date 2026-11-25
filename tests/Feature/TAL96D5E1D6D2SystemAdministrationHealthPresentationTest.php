<?php

namespace Tests\Feature;

use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Actions\SystemAdministration\SystemHealthPresenter;
use App\Filament\Pages\GovernanceAudit;
use App\Filament\Pages\SystemHealth;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

        Livewire::test(SystemHealth::class)
            ->assertOk()
            ->assertSee('App & Database')
            ->assertSee('Job Queue')
            ->assertSee('Mail / SMTP')
            ->assertSee('Scheduling Solver')
            ->assertSee('Automated database backups and disaster recovery are managed at the hosting infrastructure level.');

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

    #[Test]
    public function system_health_and_governance_present_upgraded_control_center_surfaces(): void
    {
        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($admin);

        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'presentation fixture',
            'event' => 'updated',
            'subject_type' => User::class,
            'subject_id' => $admin->getKey(),
            'causer_type' => User::class,
            'causer_id' => $admin->getKey(),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(SystemHealth::class)
            ->assertOk()
            ->assertSee('App & Database')
            ->assertSee('Job Queue')
            ->assertSee('Mail / SMTP')
            ->assertSee('Scheduling Solver')
            ->assertSee('Last checked')
            ->assertSee('Refresh status')
            ->assertSee('Technical details')
            ->assertSee('Inspect failed jobs')
            ->assertSee('Send test email')
            ->assertSee('Check solver')
            ->assertSee('Solver runs & reports', false)
            ->assertSee('Last run')
            ->assertSee('Automated database backups and disaster recovery are managed at the hosting infrastructure level.')
            ->assertDontSee('Not checked by TALA')
            ->assertDontSee('Prospective RPO target')
            ->assertDontSee('Prospective RTO target')
            ->assertDontSee('—');

        $governance = Livewire::test(GovernanceAudit::class)
            ->assertOk()
            ->assertSee('tala-navigation', escape: false)
            ->assertSee('Institutional changes')
            ->assertSee('System events')
            ->assertSee('Output access')
            ->assertSee('Privacy & retention')
            ->assertSee('Export CSV')
            ->assertSee('When')
            ->assertSee('Event')
            ->assertSee('Who')
            ->assertSee('Target')
            ->assertSee('Summary')
            ->assertDontSee('—');

        $governance
            ->call('setActiveTab', GovernanceEvidenceProjection::PrivacyRetention)
            ->assertSee('Automatic retention disposal: Not provided in this MVP')
            ->assertSee('External compliance status: Not evaluated by TALA')
            ->assertSee("Automatic record disposal is not available in TALA. Follow the institution's approved privacy and records procedure.", escape: false);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
