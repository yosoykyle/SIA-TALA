<?php

namespace Tests\Feature;

use App\Actions\StudentHub\StudentDashboardService;
use App\Filament\Student\Pages\Dashboard;
use App\Filament\Student\Pages\Profile;
use App\Models\Hold;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TAL-91D: Academic Status Student-Safe Regression (final sub-slice of parent TAL-91).
 *
 * Owning contract: PRD `00_Project_Documents/prd_modules/12_student_hub.md`
 * §12.1 (item 10 grades, item 11 "Academic deficiency or irregular status
 * summary if approved", page map row "Holds view" and "Grades view"),
 * §12.2 rules 1-2 (student-facing reasons only; must show which office to
 * contact). Also PRD `00_Project_Documents/prd_modules/11_student_lifecycle.md`
 * §11.1.3 (Academic Standing allowed values) and §11.2 (Hold fields —
 * `staff_only_reason` is explicitly staff-only vs `student_message` /
 * `resolution_requirement`).
 *
 * Grades (`GradesView`) and Completion Review (`Completion`) are already
 * fully built and tested (TAL-89D, `StudentHubCompletionReviewTest`); this
 * test file does not duplicate that coverage.
 */
final class TAL91DStudentHubAcademicStatusAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach (['student', User::StaffRoleRegistrar] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function holds_view_shows_only_the_acting_students_own_active_hold_rows(): void
    {
        [$studentA, $profileA] = $this->studentWithProfile();
        [, $profileB] = $this->studentWithProfile();

        $holdA = $this->activeHold($profileA, Hold::TypeFinancial, 'Student A pending fee.');
        $holdB = $this->activeHold($profileB, Hold::TypeFinancial, 'Student B private fee.');

        Livewire::actingAs($studentA)
            ->test(Dashboard::class)
            ->assertSee($holdA->studentFacingMessage())
            ->assertDontSee($holdB->studentFacingMessage());
    }

    #[Test]
    public function holds_view_excludes_resolved_waived_and_expired_holds_for_the_acting_student(): void
    {
        [$student, $profile] = $this->studentWithProfile();

        $active = $this->activeHold($profile, Hold::TypeFinancial, 'Active hold message');
        $resolved = Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusResolved,
            'student_message' => 'Resolved hold message',
            'effective_at' => now()->subDay(),
        ]);
        $waived = Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusWaived,
            'student_message' => 'Waived hold message',
            'effective_at' => now()->subDay(),
        ]);
        $expired = Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
            'student_message' => 'Expired hold message',
            'effective_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
        ]);

        Livewire::actingAs($student)
            ->test(Dashboard::class)
            ->assertSee($active->studentFacingMessage())
            ->assertDontSee($resolved->studentFacingMessage())
            ->assertDontSee($waived->studentFacingMessage())
            ->assertDontSee($expired->studentFacingMessage());
    }

    #[Test]
    public function holds_view_office_to_contact_column_uses_the_student_facing_office_label_mapping(): void
    {
        [$student, $profile] = $this->studentWithProfile();

        $financial = $this->activeHold($profile, Hold::TypeFinancial);
        $academicDeficit = $this->activeHold($profile, Hold::TypeAcademicDeficit);
        $documentary = $this->activeHold($profile, Hold::TypeDocumentary);

        $this->assertSame('Accounting Office', $financial->studentFacingOfficeLabel());
        $this->assertSame('Registrar Office', $academicDeficit->studentFacingOfficeLabel());
        $this->assertSame('Registrar Office', $documentary->studentFacingOfficeLabel());
    }

    #[Test]
    public function holds_view_never_exposes_the_staff_only_reason_field(): void
    {
        [$student, $profile] = $this->studentWithProfile();

        Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
            'effective_at' => now(),
            'staff_only_reason' => 'TAL91D INTERNAL STAFF-ONLY REASON',
            'student_message' => 'Please settle your outstanding balance.',
        ]);

        Livewire::actingAs($student)
            ->test(Dashboard::class)
            ->assertSee('Please settle your outstanding balance.')
            ->assertDontSee('TAL91D INTERNAL STAFF-ONLY REASON');
    }

    #[Test]
    public function lifecycle_view_shows_only_the_acting_students_own_applied_lifecycle_change_rows(): void
    {
        [$studentA, $profileA] = $this->studentWithProfile();
        [, $profileB] = $this->studentWithProfile();

        $term = Term::factory()->create();

        $changeA = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profileA->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'state' => StudentLifecycleChange::StateApplied,
            'effective_on' => now()->toDateString(),
        ]);
        $changeB = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profileB->id,
            'term_id' => $term->id,
            'type' => StudentLifecycleChange::TypeWithdrawal,
            'state' => StudentLifecycleChange::StateApplied,
            'effective_on' => now()->toDateString(),
        ]);

        Livewire::actingAs($studentA)
            ->test(Profile::class)
            ->assertViewHas('lifecycleChanges', fn ($changes): bool => $changes->getCollection()->modelKeys() === [$changeA->id]);
    }

    #[Test]
    public function student_dashboard_service_projects_the_canonical_academic_effect(): void
    {
        [, $profile] = $this->studentWithProfile([
            'academic_standing' => StudentProfile::StandingProbationary,
        ]);

        $method = new \ReflectionMethod(StudentDashboardService::class, 'profile');
        $method->setAccessible(true);

        /** @var array<string,mixed> $projected */
        $projected = $method->invoke(app(StudentDashboardService::class), $profile);

        $this->assertArrayNotHasKey('academic_standing', $projected);
        $this->assertSame('ALLOWED', $projected['academic_effect']['effect']);
        $this->assertSame('Current released academic and lifecycle records', $projected['academic_effect']['source']);
    }

    /**
     * @param  array<string,mixed>  $profileAttributes
     * @return array{0: User, 1: StudentProfile}
     */
    private function studentWithProfile(array $profileAttributes = []): array
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $student->assignRole('student');

        $profile = StudentProfile::factory()->create(array_merge([
            'user_id' => $student->id,
        ], $profileAttributes));

        return [$student, $profile];
    }

    private function activeHold(StudentProfile $profile, string $holdType, ?string $message = null): Hold
    {
        return Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'hold_type' => $holdType,
            'blocking_level' => Hold::BlockingEnrollment,
            'status' => Hold::StatusActive,
            'effective_at' => now()->subDay(),
            ...($message !== null ? ['student_message' => $message] : []),
        ]);
    }
}
