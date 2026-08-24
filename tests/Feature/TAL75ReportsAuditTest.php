<?php

namespace Tests\Feature;

use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Filament\Pages\GovernanceAudit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL75ReportsAuditTest extends TestCase
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
    public function governance_audit_replaces_the_generic_report_hub_and_is_system_admin_only(): void
    {
        $this->assertContains(GovernanceAudit::class, Filament::getPanel('admin')->getPages());
        $this->assertFileDoesNotExist(app_path('Filament/Pages/ReportsAudit.php'));
        $this->assertFileDoesNotExist(app_path('Actions/Reports/OperationalReportService.php'));
        $this->assertFileDoesNotExist(app_path('Actions/Reports/ExportOperationalReport.php'));

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAccounting, User::StaffRoleAcademicHead, User::StaffRoleFaculty] as $role) {
            $this->actingAs($this->staff($role));
            $this->assertFalse(GovernanceAudit::canAccess());
        }

        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));
        $this->assertTrue(GovernanceAudit::canAccess());
        Livewire::test(GovernanceAudit::class)
            ->assertOk()
            ->assertSee('Institutional Changes')
            ->assertSee('System Events')
            ->assertSee('Output and Export Access')
            ->assertSee('Privacy and Retention Boundary')
            ->assertActionDoesNotExist('exportCsv')
            ->assertActionDoesNotExist('selectReport');
    }

    #[Test]
    public function governance_views_read_authoritative_sources_without_copying_rows(): void
    {
        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);
        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'changed',
            'event' => 'updated',
            'causer_type' => User::class,
            'causer_id' => $admin->getKey(),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = DB::table('activity_log')->count();

        $this->actingAs($admin);
        Livewire::test(GovernanceAudit::class)
            ->assertSee('Updated')
            ->call('setActiveTab', GovernanceEvidenceProjection::PrivacyRetention)
            ->assertSee('Automatic retention disposal: Not provided in this MVP')
            ->assertSee('External compliance status: Not evaluated by TALA');

        $this->assertSame($before, DB::table('activity_log')->count());
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
