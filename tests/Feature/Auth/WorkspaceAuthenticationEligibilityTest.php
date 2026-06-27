<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkspaceAuthenticationEligibilityTest extends TestCase
{
    /**
     * @use LazilyRefreshDatabase
     */
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function applicantLoginStatuses(): array
    {
        return [
            'active applicant' => [User::StatusActive],
            'pending applicant' => [User::StatusApplicantPending],
            'action required applicant' => [User::StatusApplicantActionRequired],
            'for evaluation applicant' => [User::StatusApplicantForEvaluation],
            'approved applicant' => [User::StatusApplicantApproved],
        ];
    }

    #[DataProvider('applicantLoginStatuses')]
    public function test_applicant_workflow_statuses_can_authenticate_and_reach_applicant_workspace(string $status): void
    {
        $applicant = $this->userWithRole('applicant', $status);

        $this->assertTrue($applicant->canAuthenticate());

        $this->post('/login', [
            'email' => $applicant->email,
            'password' => 'password',
        ])->assertRedirect('/applicant');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockedLoginStatuses(): array
    {
        return [
            'inactive applicant' => ['applicant', User::StatusInactive],
            'archived applicant' => ['applicant', User::StatusArchived],
            'inactive student' => ['student', User::StatusInactive],
            'archived student' => ['student', User::StatusArchived],
            'inactive registrar' => [User::StaffRoleRegistrar, User::StatusInactive],
            'archived registrar' => [User::StaffRoleRegistrar, User::StatusArchived],
        ];
    }

    #[DataProvider('blockedLoginStatuses')]
    public function test_inactive_or_archived_users_cannot_authenticate(string $role, string $status): void
    {
        $user = $this->userWithRole($role, $status);

        $this->assertFalse($user->canAuthenticate());

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');
    }

    public function test_active_student_can_authenticate_and_reach_student_hub(): void
    {
        $student = $this->userWithRole('student', User::StatusActive);

        $this->assertTrue($student->canAuthenticate());

        $this->post('/login', [
            'email' => $student->email,
            'password' => 'password',
        ])->assertRedirect('/student');
    }

    public function test_active_verified_staff_can_authenticate_and_reach_staff_workspace(): void
    {
        $registrar = $this->userWithRole(User::StaffRoleRegistrar, User::StatusActive);

        $this->assertTrue($registrar->canAuthenticate());

        $this->post('/login', [
            'email' => $registrar->email,
            'password' => 'password',
        ])->assertRedirect('/admin');
    }

    private function userWithRole(string $role, string $status): User
    {
        $user = User::factory()->create([
            'status' => $status,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($role);

        return $user;
    }
}
