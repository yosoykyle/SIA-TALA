<?php

namespace Tests\Feature;

use App\Actions\StudentHub\StudentHubPriorityResolver;
use App\Filament\Student\Widgets\StudentPriorityNoticeWidget;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\Hold;
use App\Models\LedgerEntry;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
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
        StudentProfile::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student);

        $this->assertFalse(StudentPriorityNoticeWidget::canView());

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
    public function retired_paymongo_attempt_does_not_override_canonical_registration_review(): void
    {
        $fixture = $this->financeFixture(paymentAttemptStatus: 'pending');
        $profile = $fixture['profile'];

        $this->createReadNotification($fixture['student']);
        Hold::factory()->create([
            'student_profile_id' => $profile->id,
            'status' => Hold::StatusActive,
            'hold_type' => Hold::TypeCorDownload,
            'blocking_level' => Hold::BlockingCorPrint,
        ]);

        $result = app(StudentHubPriorityResolver::class)->resolve($profile);

        $this->assertNotNull($result);
        $this->assertSame('Pending Review', $result['tier']);
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
        $this->assertFalse(StudentPriorityNoticeWidget::canView());

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

        $this->assertTrue(StudentPriorityNoticeWidget::canView());

        Livewire::test(StudentPriorityNoticeWidget::class)
            ->assertSee('Enrollment Blocked')
            ->assertSee($hold->student_message)
            ->assertSee('Registrar Office');

        $response = $this->get('/student');
        $response->assertOk();
    }

    /**
     * @return array{profile: StudentProfile, student: User}
     */
    private function financeFixture(string $paymentAttemptStatus): array
    {
        $student = $this->studentUser();
        $program = Program::factory()->create(['code' => 'BSBA-'.fake()->unique()->numerify('###')]);
        $profile = StudentProfile::factory()->for($student)->for($program)->create([
            'student_number' => 'SIA-2026-'.fake()->unique()->numerify('####'),
        ]);
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'pending_payment',
            'registered_at' => now()->subDay(),
        ]);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '9000.00',
            'discount_total' => '0.00',
            'total' => '9000.00',
            'required_downpayment' => '2000.00',
            'activated_at' => now(),
        ]);
        $feeRule = FeeRule::query()->create([
            'code' => 'TUITION-'.fake()->unique()->numerify('###'),
            'name' => 'Tuition Fee',
            'ledger_category' => FeeRule::LedgerCategoryCharge,
            'display_category' => FeeRule::DisplayCategoryTuition,
            'program_id' => $program->id,
            'term_id' => $term->id,
            'calculation_type' => FeeRule::CalculationFixed,
            'amount' => '9000.00',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
            'authority' => 'TAL-91A fixture',
        ]);
        $line = AssessmentLine::query()->create([
            'assessment_id' => $assessment->id,
            'fee_rule_id' => $feeRule->id,
            'source_line_key' => 'tuition',
            'description_snapshot' => 'Tuition Fee',
            'quantity' => '1.0000',
            'rate' => '9000.00',
            'amount' => '9000.00',
            'line_type' => 'tuition',
        ]);
        PaymentScheduleRow::query()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'category' => PaymentScheduleRow::CategoryDownpayment,
            'due_date' => now()->addWeek()->toDateString(),
            'amount' => '2000.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'tuition',
            'amount' => '9000.00',
            'source_type' => AssessmentLine::class,
            'source_id' => $line->id,
            'description' => 'Tuition Fee',
            'posted_at' => now()->subHour(),
            'state' => 'posted',
        ]);
        PaymentAttempt::query()->create([
            'assessment_id' => $assessment->id,
            'student_profile_id' => $profile->id,
            'channel' => 'paymongo',
            'provider' => 'mock',
            'internal_reference' => 'TALA-PAY-'.fake()->unique()->uuid(),
            'amount' => '2000.00',
            'currency' => 'PHP',
            'status' => $paymentAttemptStatus,
            'metadata' => ['note' => 'TAL-91A fixture'],
        ]);

        return [
            'student' => $student,
            'profile' => $profile,
        ];
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
