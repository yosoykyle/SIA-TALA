<?php

namespace Tests\Feature;

use App\Filament\Resources\DuplicateProfileResolutionResource;
use App\Filament\Resources\DuplicateProfileResolutionResource\Pages\CreateDuplicateProfileResolution;
use App\Filament\Resources\DuplicateProfileResolutionResource\Pages\ListDuplicateProfileResolutions;
use App\Models\DuplicateProfileResolution;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL83CDuplicateProfileResolutionFilamentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleAccounting, 'student'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Permission::findOrCreate('approve-documents', 'web');
        Permission::findOrCreate('resolve-duplicate-profiles', 'web');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function resource_route_and_schema_use_current_student_profile_fields(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);

        $this->assertContains(DuplicateProfileResolutionResource::class, Filament::getPanel('admin')->getResources());
        $this->assertTrue(Route::has('filament.admin.resources.duplicate-profile-resolutions.index'));
        $this->assertTrue(Route::has('filament.admin.resources.duplicate-profile-resolutions.create'));

        $this->get(route('filament.admin.resources.duplicate-profile-resolutions.index'))
            ->assertOk();

        Livewire::test(ListDuplicateProfileResolutions::class)
            ->assertOk();

        Livewire::test(CreateDuplicateProfileResolution::class)
            ->assertOk();

        $resourceSource = File::get(app_path('Filament/Resources/DuplicateProfileResolutionResource.php'));

        $this->assertStringContainsString('duplicate_student_profile_id', $resourceSource);
        $this->assertStringContainsString('primary_student_profile_id', $resourceSource);
        $this->assertStringContainsString('duplicateStudent.student_number', $resourceSource);
        $this->assertStringContainsString('primaryStudent.student_number', $resourceSource);
        $this->assertStringNotContainsString("Select::make('duplicate_student_id')", $resourceSource);
        $this->assertStringNotContainsString("Select::make('primary_student_id')", $resourceSource);
    }

    #[Test]
    public function registrar_can_create_linked_duplicate_resolution_from_resource(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $duplicate = StudentProfile::factory()->create([
            'student_number' => 'SIA-2026-8301',
        ]);
        $duplicate->user->assignRole('student');
        $primary = StudentProfile::factory()->create([
            'student_number' => 'SIA-2026-8302',
        ]);

        $this->actingAs($registrar);

        Livewire::test(CreateDuplicateProfileResolution::class)
            ->fillForm([
                'duplicate_student_profile_id' => $duplicate->id,
                'primary_student_profile_id' => $primary->id,
                'resolution_type' => 'LINKED_DUPLICATE',
                'reason' => 'Registrar confirmed duplicate identity records.',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $duplicate->refresh();

        $this->assertSame(StudentProfile::LifecycleArchived, $duplicate->lifecycle_status);
        $this->assertNotNull($duplicate->archived_at);
        $this->assertSame($primary->id, $duplicate->merged_into_id);
        $this->assertSame(User::StatusArchived, $duplicate->user->fresh()->status);

        $this->assertDatabaseHas('duplicate_profile_resolutions', [
            'duplicate_student_profile_id' => $duplicate->id,
            'primary_student_profile_id' => $primary->id,
            'resolution_type' => 'LINKED_DUPLICATE',
            'reason' => 'Registrar confirmed duplicate identity records.',
            'resolved_by' => $registrar->id,
        ]);
    }

    #[Test]
    public function archived_or_merged_profile_cannot_be_selected_as_primary(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $duplicate = StudentProfile::factory()->create();
        $primary = StudentProfile::factory()->create([
            'archived_at' => now(),
            'lifecycle_status' => StudentProfile::LifecycleArchived,
        ]);

        $this->actingAs($registrar);

        Livewire::test(CreateDuplicateProfileResolution::class)
            ->fillForm([
                'duplicate_student_profile_id' => $duplicate->id,
                'primary_student_profile_id' => $primary->id,
                'resolution_type' => 'LINKED_DUPLICATE',
                'reason' => 'Attempted invalid primary profile.',
            ])
            ->call('create')
            ->assertHasFormErrors(['primary_student_profile_id']);

        $this->assertFalse(DuplicateProfileResolution::query()
            ->where('duplicate_student_profile_id', $duplicate->id)
            ->exists());
    }

    #[Test]
    public function staff_with_document_permissions_but_without_registrar_role_cannot_access_or_create(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $accounting->givePermissionTo('approve-documents');
        $accounting->givePermissionTo('resolve-duplicate-profiles');

        $this->actingAs($accounting);

        $this->assertFalse(DuplicateProfileResolutionResource::canAccess());
        $this->assertFalse(DuplicateProfileResolutionResource::canCreate());

        $this->get(route('filament.admin.resources.duplicate-profile-resolutions.index'))
            ->assertForbidden();

        Livewire::test(CreateDuplicateProfileResolution::class)
            ->assertForbidden();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['stored-code']);

        return $user;
    }
}
