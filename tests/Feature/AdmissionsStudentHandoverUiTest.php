<?php

namespace Tests\Feature;

use App\Filament\Resources\DuplicateProfileResolutionResource\Pages\CreateDuplicateProfileResolution;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\RelationManagers\ChecklistItemsRelationManager;
use App\Filament\Resources\StudentProfiles\RelationManagers\HoldsRelationManager;
use App\Filament\Student\Pages\Profile as StudentProfilePage;
use App\Models\ChecklistItem;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdmissionsStudentHandoverUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles & permissions
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Role::findOrCreate(User::StaffRoleAccounting, 'web');
        Role::findOrCreate('student', 'web');
        Permission::findOrCreate('approve-documents', 'web');
        Permission::findOrCreate('resolve-duplicate-profiles', 'web');
    }

    #[Test]
    public function registrar_can_view_student_profiles_list_and_details(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $student = User::factory()->create(['status' => User::StatusActive]);
        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'student_number' => 'SIA-2026-0001',
        ]);

        $this->actingAs($registrar);

        $response = $this->get(route('filament.admin.resources.student-profiles.index'));
        $response->assertOk();
        $response->assertSee('SIA-2026-0001');

        $response = $this->get(route('filament.admin.resources.student-profiles.view', $studentProfile));
        $response->assertOk();
    }

    #[Test]
    public function student_cannot_access_registrar_student_profiles(): void
    {
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');

        $studentProfile = StudentProfile::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student);

        $response = $this->get(route('filament.admin.resources.student-profiles.index'));
        $response->assertForbidden();

        $response = $this->get(route('filament.admin.resources.student-profiles.view', $studentProfile));
        $response->assertForbidden();
    }

    #[Test]
    public function accounting_can_record_financial_holds_on_student_profile(): void
    {
        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);

        $student = User::factory()->create(['status' => User::StatusActive]);
        $studentProfile = StudentProfile::factory()->create(['user_id' => $student->id]);

        $this->actingAs($accounting);

        Livewire::test(HoldsRelationManager::class, [
            'ownerRecord' => $studentProfile,
            'pageClass' => ViewStudentProfile::class,
        ])
            ->callTableAction('createHold', data: [
                'hold_type' => Hold::TypeFinancial,
                'blocking_level' => Hold::BlockingEnrollment,
                'reason' => 'Unpaid tuition balance',
                'effective_at' => now()->toDateTimeString(),
                'resolution_requirement' => 'Coordinate with Accounting to clear the verified balance.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('holds', [
            'student_profile_id' => $studentProfile->id,
            'hold_type' => Hold::TypeFinancial,
            'status' => Hold::StatusActive,
            'reason' => 'Unpaid tuition balance',
            'resolution_requirement' => 'Coordinate with Accounting to clear the verified balance.',
        ]);
    }

    #[Test]
    public function registrar_can_verify_and_reject_checklist_items(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->givePermissionTo('approve-documents');

        $student = User::factory()->create(['status' => User::StatusActive]);
        $studentProfile = StudentProfile::factory()->create(['user_id' => $student->id]);

        $item = ChecklistItem::factory()->forStudent($studentProfile)->create([
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);

        $this->actingAs($registrar);

        $verifyComponent = Livewire::test(ChecklistItemsRelationManager::class, [
            'ownerRecord' => $studentProfile,
            'pageClass' => ViewStudentProfile::class,
        ]);
        $verifyComponent
            ->callTableAction('recordPhysicalReceipt', $item, [
                'receipt_reference' => 'PHYSICAL-VERIFY-001',
            ])
            ->assertHasNoTableActionErrors();
        $verifyComponent
            ->callTableAction('verifyDocument', $item)
            ->assertHasNoTableActionErrors();

        $item->refresh();
        $this->assertEquals(ChecklistItem::StatusAccepted, $item->status);
        $this->assertEquals(ChecklistItem::VerificationVerified, $item->verification_status);
        $this->assertEquals($registrar->id, $item->reviewed_by);

        // Reset
        $item->update([
            'status' => ChecklistItem::StatusPending,
            'verification_status' => ChecklistItem::VerificationNotReviewed,
        ]);

        $rejectComponent = Livewire::test(ChecklistItemsRelationManager::class, [
            'ownerRecord' => $studentProfile,
            'pageClass' => ViewStudentProfile::class,
        ]);
        $rejectComponent
            ->callTableAction('recordPhysicalReceipt', $item, [
                'receipt_reference' => 'PHYSICAL-REJECT-001',
            ])
            ->assertHasNoTableActionErrors();
        $rejectComponent
            ->callTableAction('rejectDocument', $item, [
                'notes' => 'Invalid birth certificate copy',
            ])
            ->assertHasNoTableActionErrors();

        $item->refresh();
        $this->assertEquals(ChecklistItem::StatusRejected, $item->status);
        $this->assertEquals(ChecklistItem::VerificationRejected, $item->verification_status);
        $this->assertEquals('Invalid birth certificate copy', $item->waiver_reason);
    }

    #[Test]
    public function registrar_can_resolve_duplicate_profiles(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->givePermissionTo('resolve-duplicate-profiles');

        $studentA = StudentProfile::factory()->create();
        $studentB = StudentProfile::factory()->create();

        $this->actingAs($registrar);

        Livewire::test(CreateDuplicateProfileResolution::class)
            ->fillForm([
                'duplicate_student_profile_id' => $studentA->id,
                'primary_student_profile_id' => $studentB->id,
                'resolution_type' => 'LINKED_DUPLICATE',
                'reason' => 'Identical LRN and birthdate records',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $studentA->refresh();
        $this->assertNotNull($studentA->archived_at);
        $this->assertEquals($studentB->id, $studentA->merged_into_id);

        $this->assertDatabaseHas('duplicate_profile_resolutions', [
            'duplicate_student_profile_id' => $studentA->id,
            'primary_student_profile_id' => $studentB->id,
            'resolution_type' => 'LINKED_DUPLICATE',
            'reason' => 'Identical LRN and birthdate records',
        ]);
    }

    #[Test]
    public function student_can_view_profile_and_edit_self_service_fields(): void
    {
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');

        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'phone' => '09170000000',
        ]);

        $this->actingAs($student);

        Livewire::test(StudentProfilePage::class)
            ->assertFormExists()
            ->fillForm([
                'email' => 'updatedemail@example.com',
                'phone' => '09179999999',
            ])
            ->call('saveProfile')
            ->assertHasNoFormErrors();

        $studentProfile->refresh();
        $this->assertEquals('updatedemail@example.com', $studentProfile->email);
        $this->assertEquals('09179999999', $studentProfile->phone);
    }
}
