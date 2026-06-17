<?php

namespace Tests\Feature;

use App\Filament\Resources\InstallmentPolicies\InstallmentPolicyResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FilamentRuntimeCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::staffRoleNames() as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_filament_html_sanitizer_runs_on_project_php_runtime(): void
    {
        $sanitized = str('<strong>Saved</strong><script>alert("x")</script>')->sanitizeHtml()->toString();

        $this->assertStringContainsString('<strong>Saved</strong>', $sanitized);
        $this->assertStringNotContainsString('<script>', $sanitized);
    }

    public function test_accounting_can_render_installment_policy_create_page(): void
    {
        $accounting = $this->staffUser(User::StaffRoleAccounting, ['create-assessments']);

        $this->actingAs($accounting)
            ->get(InstallmentPolicyResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Create Installment Policy', false);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(string $roleName, array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create();
        $user->assignRole($roleName);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
