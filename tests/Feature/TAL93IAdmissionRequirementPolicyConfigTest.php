<?php

namespace Tests\Feature;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TAL93IAdmissionRequirementPolicyConfigTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'approve-documents',
            'evaluate-transferees',
            'manage-student-profiles',
            'manage-admission-setup',
            'manage-schedules',
            'manage-sections',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_registrar_can_access_the_canonical_admissions_surfaces(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $registrar->saveAppAuthenticationRecoveryCodes(['stored-code']);
        $this->actingAs($registrar);

        $this->assertTrue(AdmissionCycleResource::canAccess());
        $this->assertTrue(AdmissionApplicationResource::canAccess());

        $this->get(AdmissionCycleResource::getUrl('index'))->assertOk();
        $this->get(AdmissionApplicationResource::getUrl('index'))->assertOk();
    }
}
