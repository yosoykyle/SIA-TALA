<?php

namespace Tests\Feature;

use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Filament\Pages\GovernanceAudit;
use App\Models\OperationalEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL92BAuditCoverageTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function governance_projects_authentication_and_operational_events_without_raw_properties_or_payloads(): void
    {
        Role::query()->firstOrCreate(['name' => User::StaffRoleSystemSuperAdmin, 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::StatusActive]);
        $admin->assignRole(User::StaffRoleSystemSuperAdmin);
        DB::table('activity_log')->insert([
            'log_name' => 'authentication',
            'description' => 'login failed',
            'event' => 'login_failed',
            'causer_type' => User::class,
            'causer_id' => $admin->getKey(),
            'properties' => json_encode(['attempted_identifier' => 'hidden@example.test'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        OperationalEvent::factory()->failed()->create([
            'event_type' => 'integration_failure',
            'payload' => ['credential' => 'payload-secret'],
            'diagnostics' => ['exception' => 'diagnostic-secret'],
            'recipient_snapshot' => ['email' => 'recipient-secret@example.test'],
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $html = Livewire::test(GovernanceAudit::class)
            ->call('setActiveTab', GovernanceEvidenceProjection::SystemEvents)
            ->assertSee('Login Failed')
            ->assertSee('Integration Failure')
            ->html();

        foreach (['hidden@example.test', 'payload-secret', 'diagnostic-secret', 'recipient-secret@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        $this->assertFileDoesNotExist(app_path('Filament/Resources/Activities/ActivityResource.php'));
    }
}
