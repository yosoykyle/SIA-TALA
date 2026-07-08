<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentProfiles\Pages\EditStudentProfile;
use App\Filament\Student\Pages\Profile as StudentProfilePage;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TAL-93C: Personal-data correction aligned to PRD 03 §3.5.
 *
 * The legacy in-system correction-request/approve feature was retired (it contradicted
 * §3.5). Locked identity fields (name, date of birth, prior-education identifier / LRN)
 * are updated by the Registrar via Admin-Override; contact fields are student self-service.
 * Both write an audit log (§3.5 rule 2).
 */
class TAL93CProfileUpdateAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        Role::findOrCreate('student', 'web');
    }

    #[Test]
    public function registrar_admin_override_updates_locked_identity_fields_and_writes_audit_log(): void
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        $student = User::factory()->create(['status' => User::StatusActive]);
        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'birth_date' => '2004-01-01',
            'prior_identifier' => null,
        ]);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditStudentProfile::class, ['record' => $studentProfile->getRouteKey()])
            ->fillForm([
                'last_name' => 'Dela Cruz',
                'birth_date' => '2005-05-15',
                'prior_identifier' => '123456789012',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $studentProfile->refresh();
        $this->assertSame('Dela Cruz', $studentProfile->last_name);
        $this->assertSame('2005-05-15', $studentProfile->birth_date->format('Y-m-d'));
        $this->assertSame('123456789012', $studentProfile->prior_identifier);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'student_profile_updated',
            'subject_id' => $studentProfile->id,
            'causer_id' => $registrar->id,
        ]);
    }

    #[Test]
    public function student_self_service_update_writes_audit_log(): void
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
                'phone' => '09179999999',
            ])
            ->call('saveProfile')
            ->assertHasNoFormErrors();

        $studentProfile->refresh();
        $this->assertSame('09179999999', $studentProfile->phone);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'student_profile_self_service_update',
            'subject_id' => $studentProfile->id,
            'causer_id' => $student->id,
        ]);
    }
}
