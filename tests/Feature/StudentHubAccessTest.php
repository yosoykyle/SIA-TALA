<?php

namespace Tests\Feature;

use App\Models\FaqEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StudentHubAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_guest_is_redirected_from_student_hub(): void
    {
        $this->get(route('student.dashboard'))
            ->assertRedirect('/login');
    }

    public function test_inactive_student_is_blocked_from_student_hub(): void
    {
        $student = $this->userWithRole('student', [
            'status' => User::StatusInactive,
        ]);

        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertForbidden();
    }

    public function test_active_applicant_is_blocked_from_student_hub(): void
    {
        $applicant = $this->userWithRole('applicant');

        $this->actingAs($applicant)
            ->get(route('student.dashboard'))
            ->assertForbidden();
    }

    public function test_active_student_can_access_student_hub(): void
    {
        $student = $this->userWithRole('student');

        $this->actingAs($student);

        foreach ($this->studentHubPages() as $routeName => $expectedText) {
            $this->get(route($routeName))
                ->assertOk()
                ->assertSeeText($expectedText, false);
        }
    }

    public function test_student_help_displays_only_published_faq_entries(): void
    {
        $student = $this->userWithRole('student');

        FaqEntry::query()->create([
            'question' => 'How do I request documents?',
            'answer' => 'Open the Documents page and follow the request flow.',
            'category' => FaqEntry::CategoryDocumentsRequests,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        FaqEntry::query()->create([
            'question' => 'Hidden staff draft',
            'answer' => 'This should not be visible.',
            'category' => FaqEntry::CategoryGeneral,
            'sort_order' => 1,
            'is_published' => false,
        ]);

        $this->actingAs($student)
            ->get(route('student.help'))
            ->assertOk()
            ->assertSee('Documents / Requests')
            ->assertSee('How do I request documents?')
            ->assertSee('Open the Documents page and follow the request flow.')
            ->assertDontSee('Hidden staff draft');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $role, array $attributes = []): User
    {
        Role::findOrCreate($role);

        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function studentHubPages(): array
    {
        return [
            'student.dashboard' => 'Welcome back, Student!',
            'student.schedule' => 'Class Schedule',
            'student.grades' => 'Academic Grades',
            'student.financials' => 'Financial Account',
            'student.documents' => 'Document Requests',
            'student.help' => 'Help & FAQ',
        ];
    }
}
