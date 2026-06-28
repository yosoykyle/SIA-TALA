<?php

namespace Tests\Feature;

use App\Actions\Enrollment\PersonalDataCorrectionService;
use App\Filament\Resources\DuplicateProfileResolutionResource\Pages\CreateDuplicateProfileResolution;
use App\Filament\Resources\PersonalDataCorrectionRequestResource\Pages\ViewPersonalDataCorrectionRequest;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\RelationManagers\ChecklistItemsRelationManager;
use App\Filament\Resources\StudentProfiles\RelationManagers\HoldsRelationManager;
use App\Filament\Student\Pages\Profile as StudentProfilePage;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\Hold;
use App\Models\PersonalDataCorrectionRequest;
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
            'student_id' => 'SIA-2026-0001',
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
    public function registrar_can_manage_holds_on_student_profile(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $student = User::factory()->create(['status' => User::StatusActive]);
        $studentProfile = StudentProfile::factory()->create(['user_id' => $student->id]);

        $this->actingAs($registrar);

        Livewire::test(HoldsRelationManager::class, [
            'ownerRecord' => $studentProfile,
            'pageClass' => ViewStudentProfile::class,
        ])
            ->callTableAction('create', data: [
                'hold_type' => Hold::TypeFinancial,
                'blocking_level' => Hold::BlockingEnrollment,
                'status' => Hold::StatusActive,
                'reason' => 'Unpaid tuition balance',
                'effective_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('holds', [
            'student_profile_id' => $studentProfile->id,
            'hold_type' => Hold::TypeFinancial,
            'status' => Hold::StatusActive,
            'reason' => 'Unpaid tuition balance',
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

        $item = ChecklistItem::factory()->create([
            'owner_type' => StudentProfile::class,
            'owner_id' => $studentProfile->id,
            'status' => ChecklistItem::STATUS_PENDING,
            'verification_status' => ChecklistItem::VERIFICATION_STATUS_NOT_REVIEWED,
        ]);

        $this->actingAs($registrar);

        // Verify the document
        Livewire::test(ChecklistItemsRelationManager::class, [
            'ownerRecord' => $studentProfile,
            'pageClass' => ViewStudentProfile::class,
        ])
            ->callTableAction('verifyDocument', $item)
            ->assertHasNoTableActionErrors();

        $item->refresh();
        $this->assertEquals(ChecklistItem::STATUS_ACCEPTED, $item->status);
        $this->assertEquals(ChecklistItem::VERIFICATION_STATUS_VERIFIED, $item->verification_status);
        $this->assertEquals($registrar->id, $item->reviewed_by);

        // Reset
        $item->update([
            'status' => ChecklistItem::STATUS_PENDING,
            'verification_status' => ChecklistItem::VERIFICATION_STATUS_NOT_REVIEWED,
        ]);

        // Reject the document
        Livewire::test(ChecklistItemsRelationManager::class, [
            'ownerRecord' => $studentProfile,
            'pageClass' => ViewStudentProfile::class,
        ])
            ->callTableAction('rejectDocument', $item, [
                'notes' => 'Invalid birth certificate copy',
            ])
            ->assertHasNoTableActionErrors();

        $item->refresh();
        $this->assertEquals(ChecklistItem::STATUS_REJECTED, $item->status);
        $this->assertEquals(ChecklistItem::VERIFICATION_STATUS_REJECTED, $item->verification_status);
        $this->assertEquals('Invalid birth certificate copy', $item->notes);
    }

    #[Test]
    public function registrar_can_approve_personal_data_correction_requests(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $studentUser = User::factory()->create([
            'status' => User::StatusActive,
            'first_name' => 'OriginalFirst',
        ]);

        $studentProfile = StudentProfile::factory()->create(['user_id' => $studentUser->id]);
        $applicantIntake = ApplicantIntake::factory()->create(['user_id' => $studentUser->id]);

        $request = app(PersonalDataCorrectionService::class)->submitRequest($studentProfile, [
            'first_name' => 'CorrectedFirst',
        ]);

        $this->actingAs($registrar);

        Livewire::test(ViewPersonalDataCorrectionRequest::class, [
            'record' => $request->getKey(),
        ])
            ->callAction('approve')
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertEquals(PersonalDataCorrectionRequest::STATUS_APPROVED, $request->status);
        $studentUser->refresh();
        $this->assertEquals('CorrectedFirst', $studentUser->first_name);
    }

    #[Test]
    public function registrar_can_reject_personal_data_correction_requests(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $studentUser = User::factory()->create([
            'status' => User::StatusActive,
            'first_name' => 'OriginalFirst',
        ]);

        $studentProfile = StudentProfile::factory()->create(['user_id' => $studentUser->id]);
        $applicantIntake = ApplicantIntake::factory()->create(['user_id' => $studentUser->id]);

        $request = app(PersonalDataCorrectionService::class)->submitRequest($studentProfile, [
            'first_name' => 'CorrectedFirst',
        ]);

        $this->actingAs($registrar);

        Livewire::test(ViewPersonalDataCorrectionRequest::class, [
            'record' => $request->getKey(),
        ])
            ->callAction('reject', [
                'reject_reason' => 'Missing ID copy proof',
            ])
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertEquals(PersonalDataCorrectionRequest::STATUS_REJECTED, $request->status);
        $this->assertEquals('Missing ID copy proof', $request->reject_reason);
        $studentUser->refresh();
        $this->assertEquals('OriginalFirst', $studentUser->first_name);
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
                'duplicate_student_id' => $studentA->id,
                'primary_student_id' => $studentB->id,
                'resolution_type' => 'LINKED_DUPLICATE',
                'reason' => 'Identical LRN and birthdate records',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $studentA->refresh();
        $this->assertEquals('Archived', $studentA->operational_status);
        $this->assertEquals($studentB->id, $studentA->merged_into_student_id);

        $this->assertDatabaseHas('duplicate_profile_resolutions', [
            'duplicate_student_id' => $studentA->id,
            'primary_student_id' => $studentB->id,
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
            'lrn' => '123456789012',
        ]);

        $applicantIntake = ApplicantIntake::factory()->create([
            'user_id' => $student->id,
            'contact_number' => '09170000000',
            'street' => 'Street Name',
            'barangay' => 'Barangay',
            'city' => 'City',
            'province' => 'Province',
            'region' => 'Region',
            'zip_code' => '1234',
            'guardian_name' => 'Guardian Name',
            'guardian_contact_number' => '09171111111',
            'guardian_address' => 'Guardian Address',
        ]);

        $this->actingAs($student);

        Livewire::test(StudentProfilePage::class)
            ->assertFormExists()
            ->fillForm([
                'email' => 'updatedemail@example.com',
                'contact_number' => '09179999999',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $student->refresh();
        $this->assertEquals('updatedemail@example.com', $student->email);
        $applicantIntake->refresh();
        $this->assertEquals('09179999999', $applicantIntake->contact_number);
    }

    #[Test]
    public function student_can_submit_correction_request(): void
    {
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');

        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'lrn' => '123456789012',
        ]);

        $applicantIntake = ApplicantIntake::factory()->create([
            'user_id' => $student->id,
            'street' => 'Street Name',
            'barangay' => 'Barangay',
            'city' => 'City',
            'province' => 'Province',
            'region' => 'Region',
            'zip_code' => '1234',
            'guardian_name' => 'Guardian Name',
            'guardian_contact_number' => '09171111111',
            'guardian_address' => 'Guardian Address',
        ]);

        $this->actingAs($student);

        Livewire::test(StudentProfilePage::class)
            ->callAction('requestCorrection', [
                'first_name' => 'CorrectedName',
                'last_name' => 'CorrectedLast',
                'birthdate' => '2000-01-01',
                'lrn' => '987654321098',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('personal_data_correction_requests', [
            'student_profile_id' => $studentProfile->id,
            'status' => PersonalDataCorrectionRequest::STATUS_PENDING,
        ]);
    }
}
