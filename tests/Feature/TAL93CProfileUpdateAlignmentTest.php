<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentProfiles\Pages\EditStudentProfile;
use App\Filament\Student\Pages\Profile as StudentProfilePage;
use App\Models\StudentProfile;
use App\Models\StudentProfileEvent;
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
    public function registrar_records_an_attributable_append_only_factual_correction(): void
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
            ->callAction('recordCorrection', data: [
                'first_name' => 'Juan',
                'middle_name' => $studentProfile->middle_name,
                'last_name' => 'Dela Cruz',
                'birth_date' => '2005-05-15',
                'prior_identifier' => '123456789012',
                'email' => $studentProfile->email,
                'phone' => $studentProfile->phone,
                'address' => $studentProfile->address,
                'entry_term_id' => $studentProfile->entry_term_id,
                'authority_reference' => 'Registrar correction ticket RC-2026-001',
                'reason' => 'Corrected against the authenticated admission record.',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Student Profile correction recorded');

        $studentProfile->refresh();
        $this->assertSame('Dela Cruz', $studentProfile->last_name);
        $this->assertSame('2005-05-15', $studentProfile->birth_date->format('Y-m-d'));
        $this->assertSame('123456789012', $studentProfile->prior_identifier);

        $event = StudentProfileEvent::query()->sole();
        $this->assertSame($studentProfile->id, $event->student_profile_id);
        $this->assertSame(StudentProfileEvent::TypeCorrection, $event->event_type);
        $this->assertSame('Registrar correction ticket RC-2026-001', $event->authority_reference);
        $this->assertSame(['last_name', 'birth_date', 'prior_identifier'], $event->changed_fields);
        $this->assertSame($registrar->id, $event->actor_id);

        $this->expectException(\LogicException::class);
        $event->update(['reason' => 'History cannot be rewritten.']);
    }

    #[Test]
    public function student_profile_projection_does_not_offer_a_self_service_write_path(): void
    {
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');

        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'phone' => '09170000000',
        ]);
        StudentProfileEvent::factory()->for($studentProfile)->create([
            'reason' => 'Corrected against the authenticated Registrar record.',
            'effective_at' => '2026-08-29 10:00:00',
        ]);

        $this->actingAs($student);

        Livewire::test(StudentProfilePage::class)
            ->assertSee('Official Student Record')
            ->assertSee('Correction guidance')
            ->assertSee('Recorded correction history')
            ->assertSee('Corrected against the authenticated Registrar record.')
            ->assertDontSee('Save Contact Details');

        $studentProfile->refresh();
        $this->assertSame('09170000000', $studentProfile->phone);

        $this->assertDatabaseMissing('activity_log', [
            'event' => 'student_profile_self_service_update',
            'subject_id' => $studentProfile->id,
            'causer_id' => $student->id,
        ]);
    }
}
