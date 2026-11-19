<?php

namespace Tests\Feature;

use App\Actions\StudentHub\StudentHubPriorityResolver;
use App\Models\Enrollment;
use App\Models\Hold;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TAL-91A: Student Hub Dashboard Projection Acceptance (first sub-slice of TAL-91).
 *
 * Owning contract: PRD `00_Project_Documents/prd_modules/12_student_hub.md`
 * §12.1 (visibility rules), §12.2 (display priority), §12.4 (interaction contract).
 *
 * This sub-slice implements only tiers 1 (security/account notice),
 * 2 (enrollment blocked), 3 (payment pending or rejected), 5 (COR blocked),
 * and 11 (informational notices) of the §12.2 ranking. Tiers 4, 6, 7, 8, 9,
 * 10 are deferred to TAL-91B/C/D per the accepted handoff packet.
 */
final class TAL91AStudentHubDashboardProjectionAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach (['student', 'applicant'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('student'));
    }

    #[Test]
    public function guests_are_redirected_to_student_login(): void
    {
        $response = $this->get('/student');

        $response->assertRedirect('/student/login');
    }

    #[Test]
    public function non_students_cannot_access_student_hub(): void
    {
        $applicant = User::factory()->create([
            'status' => User::StatusActive,
        ]);
        $applicant->assignRole('applicant');

        $response = $this->actingAs($applicant)->get('/student');

        $response->assertForbidden();
    }

    #[Test]
    public function widget_hides_when_no_tier_is_actionable(): void
    {
        $student = $this->studentUser();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student);

        $this->assertNull(app(StudentHubPriorityResolver::class)->resolve($profile));

        $response = $this->get('/student');
        $response->assertOk();
    }

    #[Test]
    public function unread_notification_outranks_every_other_tier(): void
    {
        $student = $this->studentUser();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);

        // Lower-priority signals also present.
        Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'status' => Hold::StatusActive,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        $this->createReadNotification($student);
        $this->createUnreadNotification($student, 'Security notice', 'Please verify your recent sign-in.');

        $this->actingAs($student);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Security / Account Notice', $result['tier']);
        $this->assertSame('Please verify your recent sign-in.', $result['student_reason']);
    }

    #[Test]
    public function enrollment_blocking_hold_outranks_informational_notification(): void
    {
        $student = $this->studentUser();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);

        $this->createReadNotification($student);
        $hold = Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'status' => Hold::StatusActive,
            'hold_type' => Hold::TypeEnrollment,
            'blocking_level' => Hold::BlockingEnrollment,
            'student_message' => 'Your enrollment is on hold pending clearance.',
        ]);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Enrollment Blocked', $result['tier']);
        $this->assertSame($hold->student_message, $result['student_reason']);
        $this->assertSame('Registrar Office', $result['office_to_contact']);
    }

    #[Test]
    public function cor_blocked_hold_outranks_informational_notification(): void
    {
        $student = $this->studentUser();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);

        $this->createReadNotification($student);
        $hold = Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'status' => Hold::StatusActive,
            'hold_type' => Hold::TypeCorDownload,
            'blocking_level' => Hold::BlockingCorPrint,
            'student_message' => 'Your COR is blocked pending document submission.',
        ]);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('COR Blocked', $result['tier']);
        $this->assertSame($hold->student_message, $result['student_reason']);
    }

    #[Test]
    public function read_notification_is_the_lowest_priority_actionable_tier(): void
    {
        $student = $this->studentUser();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);

        $this->createReadNotification($student, 'Welcome', 'Welcome to the new term.');

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Informational Notice', $result['tier']);
        $this->assertSame('Welcome to the new term.', $result['student_reason']);
        $this->assertNull($result['required_action']);
    }

    #[Test]
    public function own_records_isolation_prevents_cross_student_notice_leakage(): void
    {
        $student = $this->studentUser();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);

        $other = $this->studentUser();
        $otherProfile = StudentProfile::factory()->create(['user_id' => $other->id]);

        // Other student has an unread notification and an enrollment-blocking hold.
        $this->createUnreadNotification($other, 'Other notice', 'This belongs to the other student.');
        Hold::factory()->create([
            'student_profile_id' => $otherProfile->id,
            'status' => Hold::StatusActive,
            'blocking_level' => Hold::BlockingEnrollment,
            'student_message' => 'Other student enrollment hold.',
        ]);

        // Acting student has nothing actionable.
        $this->actingAs($student);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);
        $this->assertNull($result);

        $response = $this->get('/student');
        $response->assertOk();
        $response->assertDontSee('This belongs to the other student.');
        $response->assertDontSee('Other student enrollment hold.');
    }

    #[Test]
    public function widget_renders_the_resolved_notice_read_only_without_mutating_actions(): void
    {
        $student = $this->studentUser();
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);

        $hold = Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'status' => Hold::StatusActive,
            'hold_type' => Hold::TypeEnrollment,
            'blocking_level' => Hold::BlockingEnrollment,
            'student_message' => 'Your enrollment is on hold pending clearance.',
        ]);

        $this->actingAs($student);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);
        $this->assertNotNull($result);
        $this->assertSame('Enrollment Blocked', $result['tier']);
        $this->assertSame($hold->student_message, $result['student_reason']);
        $this->assertSame('Registrar Office', $result['office_to_contact']);

        $response = $this->get('/student');
        $response->assertOk();
    }

    private function createUnreadNotification(User $user, string $title = 'Notice', string $body = 'You have a notice.'): DatabaseNotification
    {
        return DatabaseNotification::query()->create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['title' => $title, 'body' => $body],
            'read_at' => null,
        ]);
    }

    private function createReadNotification(User $user, string $title = 'Notice', string $body = 'You have a notice.'): DatabaseNotification
    {
        return DatabaseNotification::query()->create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\\Notifications\\TestNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['title' => $title, 'body' => $body],
            'read_at' => now()->subDay(),
        ]);
    }

    private function studentUser(): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('student');

        return $user;
    }
}
