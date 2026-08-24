<?php

namespace Tests\Feature;

use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\RolePolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TAL-92F: Remaining System Configuration acceptance (parent TAL-92 closure).
 *
 * Owning contract: PRD `13_system_admin_reports_audit.md` §13.1 (System
 * Configuration) — specifically §13.1.2 configuration-governance rules
 * (actor/timestamp/previous-value/new-value/reason; effective-dating
 * preserves historical behavior; traceability) and §13.1.1 item 16
 * (Role permissions as a configurable, Super-Admin-governed record).
 *
 * Complementary historical coverage only. The canonical System Health and
 * Governance surfaces no longer expose a peer System Setting resource. This
 * test proves the retained runtime storage semantics: two
 * config-governance *storage semantics* of the `system_settings` table — two
 * coexisting synthetic versions of one key preserved with full audit metadata.
 * The maintenance key is a governance fixture only; this test does not prove
 * that the running application consumes it or enters Laravel maintenance mode.
 * This slice also proves
 * (A2) the `RolePolicy` / `RoleResource` role-permission configuration
 * surface is read-only and Super-Admin-only.
 */
final class TAL92FSystemConfigurationAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        foreach ([...User::staffRoleNames(), 'student', 'applicant'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    // ------------------------------------------------------------------
    // A1 — §13.1.2 configuration-governance semantics on system_settings.
    // ------------------------------------------------------------------

    #[Test]
    public function two_coexisting_versions_of_one_setting_key_are_preserved_with_full_governance_metadata(): void
    {
        // Synthetic governance fixture for rules 2-5: a stored version captures actor + reason,
        // effective-dating preserves the historical value, and the value
        // remains traceable across versions. This does not assert a runtime
        // maintenance effect.
        $firstActor = User::factory()->create(['status' => User::StatusActive]);
        $secondActor = User::factory()->create(['status' => User::StatusActive]);

        // Governance columns are guarded (model $fillable = ['key','value']),
        // so version rows are written via forceCreate exactly as TAL-92D does.
        $supersededV1 = SystemSetting::query()->forceCreate([
            'key' => 'maintenance_mode',
            'scope_type' => 'institution',
            'scope_id' => 0,
            'value_type' => SystemSetting::ValueTypeBoolean,
            'value' => 'false',
            'effective_from' => now()->subMonths(2),
            'effective_until' => now()->subMonth(),
            'version' => 1,
            'status' => 'superseded',
            'changed_by' => $firstActor->id,
            'reason' => 'Initial institution default: application maintenance disabled.',
        ]);

        $activeV2 = SystemSetting::query()->forceCreate([
            'key' => 'maintenance_mode',
            'scope_type' => 'institution',
            'scope_id' => 0,
            'value_type' => SystemSetting::ValueTypeBoolean,
            'value' => 'true',
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'version' => 2,
            'status' => 'active',
            'changed_by' => $secondActor->id,
            'reason' => 'Enabled maintenance for the scheduled upgrade window.',
        ]);

        // Both versions persist independently — creating v2 never overwrites v1.
        $versions = SystemSetting::query()
            ->where('key', 'maintenance_mode')
            ->where('scope_type', 'institution')
            ->where('scope_id', 0)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertSame([1, 2], $versions->pluck('version')->map(fn ($v): int => (int) $v)->all());
        $this->assertSame(['superseded', 'active'], $versions->pluck('status')->all());

        $v1 = $versions->firstWhere('version', 1);
        $v2 = $versions->firstWhere('version', 2);

        // Effective windows distinguish the historical vs. active row (rule 3).
        $this->assertNotNull($v1->effective_until, 'Superseded v1 must carry a closed effective window.');
        $this->assertNull($v2->effective_until, 'Active v2 must carry an open effective window.');

        // Effective-dating preserves the historical value (rule 3/4): v1 keeps
        // its original boolean value even after v2 changes it.
        $this->assertNotSame($v1->value, $v2->value);
        $this->assertFalse(json_decode((string) $v1->value, true), 'Historical v1 value must remain false.');
        $this->assertTrue(json_decode((string) $v2->value, true), 'Active v2 value must be true.');

        // Exactly one active version at a time (rule 4/5 traceability guard).
        $this->assertSame(1, SystemSetting::query()
            ->where('key', 'maintenance_mode')
            ->where('status', 'active')
            ->count());

        // Actor + reason traceability persisted for every version (rule 2/5).
        $this->assertDatabaseHas('system_settings', [
            'id' => $supersededV1->id,
            'key' => 'maintenance_mode',
            'version' => 1,
            'status' => 'superseded',
            'changed_by' => $firstActor->id,
            'reason' => 'Initial institution default: application maintenance disabled.',
        ]);

        $this->assertDatabaseHas('system_settings', [
            'id' => $activeV2->id,
            'key' => 'maintenance_mode',
            'version' => 2,
            'status' => 'active',
            'changed_by' => $secondActor->id,
            'reason' => 'Enabled maintenance for the scheduled upgrade window.',
        ]);
    }

    // ------------------------------------------------------------------
    // A2 — §13.1.1 item 16: role-permission configuration surface.
    // ------------------------------------------------------------------

    #[Test]
    public function role_permission_configuration_is_read_only_and_super_admin_only(): void
    {
        // Role permissions (PRD §13.1.1 item 16) are configuration governed by
        // the same "only authorized roles can configure" rule (§13.1.2 rule 1).
        // The surface is read-only and gated to Super-Admin via RolePolicy.
        // RolePolicy remains the owning authorization boundary for this surface.
        $this->assertFalse(RoleResource::canCreate());

        $policy = app(RolePolicy::class);

        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $role = Role::query()->firstOrCreate([
            'name' => User::StaffRoleRegistrar,
            'guard_name' => 'web',
        ]);

        // Super-Admin: read allowed, all mutation abilities denied (read-only).
        $this->assertTrue($policy->viewAny($superAdmin));
        $this->assertTrue($policy->view($superAdmin, $role));
        $this->assertFalse($policy->create($superAdmin));
        $this->assertFalse($policy->update($superAdmin, $role));
        $this->assertFalse($policy->delete($superAdmin, $role));
        $this->assertFalse($policy->restore($superAdmin, $role));
        $this->assertFalse($policy->forceDelete($superAdmin, $role));

        Livewire::test(ListRoles::class)->assertOk();

        // The four other staff roles are forbidden from the config surface.
        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
        ] as $deniedRole) {
            $denied = $this->staff($deniedRole);
            $this->actingAs($denied);

            $this->assertFalse($policy->viewAny($denied));

            Livewire::test(ListRoles::class)->assertForbidden();
        }
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
