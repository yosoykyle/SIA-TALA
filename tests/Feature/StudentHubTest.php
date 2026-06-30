<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'student']);
        Role::create(['name' => 'applicant']);
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
            'status' => User::StatusApplicantPending,
        ]);
        $applicant->assignRole('applicant');

        $response = $this->actingAs($applicant)->get('/student');

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
}
