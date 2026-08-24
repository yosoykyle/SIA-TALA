<?php

namespace Tests\Feature;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Models\AdmissionApplication;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrarApplicantIntakeQueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Role::findOrCreate(User::StaffRoleAccounting, 'web');
        Permission::findOrCreate('approve-documents', 'web');
    }

    public function test_authorized_registrar_sees_submitted_applications_but_not_private_drafts(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar, ['approve-documents']);
        $draft = AdmissionApplication::factory()->create();
        $submitted = AdmissionApplication::factory()->submitted()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListAdmissionApplications::class)
            ->assertCanSeeTableRecords([$submitted])
            ->assertCanNotSeeTableRecords([$draft]);
    }

    public function test_staff_without_admissions_permission_cannot_open_the_queue(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($accounting)
            ->get(AdmissionApplicationResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_canonical_admissions_resource_is_read_only_and_has_no_bulk_decisions(): void
    {
        $this->assertFalse(AdmissionApplicationResource::canCreate());
        $this->assertArrayNotHasKey('create', AdmissionApplicationResource::getPages());
        $this->assertArrayNotHasKey('edit', AdmissionApplicationResource::getPages());
    }

    /** @param list<string> $permissions */
    private function staff(string $role, array $permissions = []): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['stored-code']);

        return $user;
    }
}
