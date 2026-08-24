<?php

namespace Tests\Feature;

use App\Filament\Student\Pages\Profile;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('student', 'web');
        Role::findOrCreate('applicant', 'web');

        Filament::setCurrentPanel(Filament::getPanel('student'));
    }

    #[Test]
    public function guests_are_redirected_to_student_login()
    {
        $response = $this->get('/student');

        $response->assertRedirect('/student/login');
    }

    #[Test]
    public function non_students_cannot_access_student_hub()
    {
        $applicant = User::factory()->create([
            'status' => User::StatusActive,
        ]);
        $applicant->assignRole('applicant');

        $response = $this->actingAs($applicant)->get('/student');

        $response->assertForbidden();
    }

    #[Test]
    public function active_students_without_official_profile_cannot_access_student_hub()
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
        ]);
        $student->assignRole('student');

        $response = $this->actingAs($student)->get('/student');

        $response->assertForbidden();
    }

    #[Test]
    public function active_students_can_access_student_hub()
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
        ]);
        $student->assignRole('student');

        StudentProfile::factory()->create([
            'user_id' => $student->id,
        ]);

        $response = $this->actingAs($student)->get('/student');

        $response->assertOk();
    }

    #[Test]
    public function archived_or_merged_duplicate_profiles_do_not_activate_student_hub()
    {
        $archivedUser = User::factory()->create(['status' => User::StatusActive]);
        $archivedUser->assignRole('student');
        StudentProfile::factory()->create([
            'user_id' => $archivedUser->id,
            'lifecycle_status' => StudentProfile::LifecycleArchived,
            'archived_at' => now(),
        ]);

        $this->actingAs($archivedUser)
            ->get('/student')
            ->assertForbidden();

        $mergedUser = User::factory()->create(['status' => User::StatusActive]);
        $mergedUser->assignRole('student');
        $primary = StudentProfile::factory()->create();
        StudentProfile::factory()->create([
            'user_id' => $mergedUser->id,
            'merged_into_id' => $primary->id,
        ]);

        $this->actingAs($mergedUser)
            ->get('/student')
            ->assertForbidden();
    }

    #[Test]
    public function student_dashboard_renders_with_profile_and_holds_widgets()
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
        ]);
        $student->assignRole('student');

        $profile = StudentProfile::factory()->create([
            'user_id' => $student->id,
        ]);

        Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'status' => Hold::StatusActive,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);

        $this->actingAs($student);

        // Check if the dashboard loads properly with Filament widgets
        $response = $this->get('/student');
        $response->assertOk();

        // The dashboard page component should exist
        // Livewire testing is preferred, but simple HTTP check is good for shells
    }

    #[Test]
    public function student_pages_render_correctly()
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
        ]);
        $student->assignRole('student');

        StudentProfile::factory()->create([
            'user_id' => $student->id,
        ]);

        $this->actingAs($student);

        // All student hub shell pages
        $pages = [
            '/student/student-profile',
            '/student/cor-view',
            '/student/finance',
            '/student/schedule-view',
            '/student/grades-view',
            '/student/holds-view',
        ];

        foreach ($pages as $url) {
            $response = $this->get($url);
            $response->assertOk();
        }

        $this->get('/student/grades-view')
            ->assertOk()
            ->assertSee('Grades will appear here after posting and release.');
    }

    #[Test]
    public function student_profile_page_updates_only_allowed_self_service_fields()
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
        ]);
        $student->assignRole('student');

        $profile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'first_name' => 'Locked',
            'last_name' => 'Student',
            'student_number' => 'SIA-2026-8399',
            'phone' => '09170000000',
            'email' => 'old.student@example.test',
            'address' => 'Old Address',
            'emergency_contact_name' => 'Old Guardian',
            'emergency_contact_phone' => '09171111111',
        ]);

        Livewire::actingAs($student)
            ->test(Profile::class)
            ->assertSee('Official Student Record')
            ->assertSee('SIA-2026-8399')
            ->set('data.phone', '09171234567')
            ->set('data.email', 'new.student@example.test')
            ->set('data.address', '123 New Street, Manila')
            ->set('data.emergency_contact_name', 'New Guardian')
            ->set('data.emergency_contact_phone', '09177654321')
            ->set('data.first_name', 'Changed')
            ->call('saveProfile')
            ->assertNotified('Profile contact details saved');

        $profile->refresh();

        $this->assertSame('09171234567', $profile->phone);
        $this->assertSame('new.student@example.test', $profile->email);
        $this->assertSame('123 New Street, Manila', $profile->address);
        $this->assertSame('New Guardian', $profile->emergency_contact_name);
        $this->assertSame('09177654321', $profile->emergency_contact_phone);
        $this->assertSame('Locked', $profile->first_name);
        $this->assertSame('SIA-2026-8399', $profile->student_number);
    }
}
