<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PreUatInternalRouteDenialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_faq_entry_routes_are_registered_for_authorized_system_super_admin(): void
    {
        $this->actingAs($this->systemSuperAdmin())
            ->get('/admin/faq-entries')
            ->assertOk();

        $this->actingAs($this->systemSuperAdmin())
            ->get('/admin/faq-entries/create')
            ->assertOk();

        $this->actingAs($this->systemSuperAdmin())
            ->get('/admin/faq-entries/1/edit')
            ->assertNotFound();
    }

    public function test_system_settings_direct_url_is_forbidden_even_for_system_super_admin(): void
    {
        $this->actingAs($this->systemSuperAdmin())
            ->get('/admin/system-settings')
            ->assertForbidden();
    }

    private function systemSuperAdmin(): User
    {
        Permission::findOrCreate('manage-faqs');
        Role::findOrCreate(User::StaffRoleSystemSuperAdmin);

        $user = User::factory()->create([
            'status' => User::StatusActive,
        ]);

        $user->assignRole(User::StaffRoleSystemSuperAdmin);
        $user->givePermissionTo('manage-faqs');

        return $user;
    }
}
