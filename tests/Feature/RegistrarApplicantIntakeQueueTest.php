<?php

namespace Tests\Feature;

use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Filament\Resources\ApplicantIntakes\Pages\ListApplicantIntakes;
use App\Models\ApplicantIntake;
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

    public function test_authorized_registrar_sees_submitted_intakes_but_not_private_drafts(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar, ['approve-documents']);
        $draft = ApplicantIntake::factory()->create(['status' => ApplicantIntake::StatusDraft]);
        $submitted = ApplicantIntake::factory()->create(['status' => ApplicantIntake::StatusPending]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ListApplicantIntakes::class)
            ->assertCanSeeTableRecords([$submitted])
            ->assertCanNotSeeTableRecords([$draft]);
    }

    public function test_staff_without_admissions_permission_cannot_open_the_queue(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($accounting)
            ->get(ApplicantIntakeResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_staff_intake_resource_is_read_only(): void
    {
        $this->assertFalse(ApplicantIntakeResource::canCreate());
        $this->assertArrayNotHasKey('create', ApplicantIntakeResource::getPages());
        $this->assertArrayNotHasKey('edit', ApplicantIntakeResource::getPages());
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staff(string $role, array $permissions = []): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
