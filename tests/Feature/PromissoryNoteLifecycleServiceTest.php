<?php

namespace Tests\Feature;

use App\Actions\Finance\PromissoryNoteLifecycleService;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PromissoryNote;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Notifications\GeneralSystemNotification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PromissoryNoteLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('applicant');
        Role::findOrCreate('student');
    }

    public function test_applicant_owner_can_submit_one_pending_request_for_an_assessed_enrollment(): void
    {
        [$student, $enrollment] = $this->studentEnrollment('applicant', '2500.00');
        $service = app(PromissoryNoteLifecycleService::class);

        $note = $service->submit([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'amount' => '1500.00',
            'due_date' => now()->addDays(10)->toDateString(),
            'reason' => 'Temporary family financial emergency.',
        ], $student->user);

        $this->assertSame(PromissoryNote::StatusPending, $note->status);
        $this->assertSame($student->user_id, $note->requested_by);
        $this->assertSame(PromissoryNote::SourceStudent, $note->request_source);
        $this->assertNotNull($note->requested_at);
        $this->assertNull($note->approved_at);

        $this->expectException(RuntimeException::class);

        $service->submit([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'amount' => '500.00',
            'due_date' => now()->addDays(12)->toDateString(),
            'reason' => 'Duplicate request.',
        ], $student->user);
    }

    public function test_submission_rejects_non_owner_invalid_amount_and_past_due_date(): void
    {
        [$student, $enrollment] = $this->studentEnrollment('student', '1000.00');
        $service = app(PromissoryNoteLifecycleService::class);

        $this->expectException(AuthorizationException::class);

        $service->submit([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'amount' => '1000.01',
            'due_date' => now()->subDay()->toDateString(),
            'reason' => 'Invalid request.',
        ], User::factory()->create());
    }

    public function test_submission_validates_amount_and_due_date_after_owner_authorization(): void
    {
        [$student, $enrollment] = $this->studentEnrollment('student', '1000.00');

        try {
            app(PromissoryNoteLifecycleService::class)->submit([
                'student_profile_id' => $student->id,
                'term_id' => $enrollment->term_id,
                'enrollment_id' => $enrollment->id,
                'amount' => '1000.01',
                'due_date' => now()->subDay()->toDateString(),
                'reason' => 'Invalid request.',
            ], $student->user);

            $this->fail('Invalid amount and due date were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
            $this->assertArrayHasKey('due_date', $exception->errors());
        }
    }

    public function test_accounting_can_approve_or_reject_only_pending_requests_with_audited_reasons(): void
    {
        Notification::fake();
        [$student, $enrollment] = $this->studentEnrollment('student', '2000.00');
        $accounting = $this->accountingUser();
        $service = app(PromissoryNoteLifecycleService::class);
        $pending = $this->pendingNote($student, $enrollment);

        $approved = $service->approve($pending, $accounting);

        $this->assertSame(PromissoryNote::StatusApproved, $approved->status);
        $this->assertSame($accounting->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        Notification::assertSentTo(
            $student->user,
            GeneralSystemNotification::class,
            fn (GeneralSystemNotification $notification): bool => $notification->type === 'promissory_note_approved',
        );

        $this->expectException(RuntimeException::class);
        $service->reject($approved, $accounting, 'Cannot reject an approved request.');
    }

    public function test_rejection_requires_reason_and_records_reviewer(): void
    {
        [$student, $enrollment] = $this->studentEnrollment('student', '2000.00');
        $accounting = $this->accountingUser();
        $service = app(PromissoryNoteLifecycleService::class);
        $pending = $this->pendingNote($student, $enrollment);

        $this->expectException(RuntimeException::class);
        $service->reject($pending, $accounting, '  ');
    }

    public function test_confirmed_payment_settles_promised_amount_but_does_not_imply_zero_balance(): void
    {
        [$student, $enrollment] = $this->studentEnrollment('student', '3000.00');
        $accounting = $this->accountingUser();
        $approved = app(PromissoryNoteLifecycleService::class)->approve(
            $this->pendingNote($student, $enrollment, amount: '1000.00'),
            $accounting,
            CarbonImmutable::parse('2026-06-01 08:00:00'),
        );

        Payment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'amount' => '1000.00',
            'status' => 'confirmed',
            'confirmed_at' => '2026-06-05 09:00:00',
        ]);

        $settled = app(PromissoryNoteLifecycleService::class)->settleEligibleForEnrollment(
            $enrollment,
            $accounting,
            CarbonImmutable::parse('2026-06-05 09:01:00'),
        );

        $this->assertSame(1, $settled);
        $this->assertSame(PromissoryNote::StatusSettled, $approved->refresh()->status);
        $this->assertSame('3000.00', $student->refresh()->current_balance);
    }

    public function test_deadline_processing_warns_once_then_expires_an_unsettled_approved_note(): void
    {
        Notification::fake();
        [$student, $enrollment] = $this->studentEnrollment('student', '2000.00');
        $accounting = $this->accountingUser();
        $service = app(PromissoryNoteLifecycleService::class);
        $note = $this->pendingNote($student, $enrollment, dueDate: '2026-06-21');
        $service->approve($note, $accounting, CarbonImmutable::parse('2026-06-01 08:00:00'));

        $first = $service->processDeadlines(CarbonImmutable::parse('2026-06-18 00:45:00'));
        $second = $service->processDeadlines(CarbonImmutable::parse('2026-06-18 01:00:00'));

        $this->assertSame(['warnings_sent' => 1, 'expired' => 0], $first);
        $this->assertSame(['warnings_sent' => 0, 'expired' => 0], $second);
        $this->assertNotNull($note->refresh()->expiry_warning_sent_at);

        $expired = $service->processDeadlines(CarbonImmutable::parse('2026-06-22 00:45:00'));

        $this->assertSame(['warnings_sent' => 0, 'expired' => 1], $expired);
        $this->assertSame(PromissoryNote::StatusExpired, $note->refresh()->status);
        $this->assertNotNull($note->expired_at);

        Notification::assertSentTo(
            $student->user,
            GeneralSystemNotification::class,
            fn (GeneralSystemNotification $notification): bool => $notification->type === 'promissory_note_expiring',
        );
        Notification::assertSentTo(
            $student->user,
            GeneralSystemNotification::class,
            fn (GeneralSystemNotification $notification): bool => $notification->type === 'promissory_note_expired',
        );
    }

    /**
     * @return array{StudentProfile, Enrollment}
     */
    private function studentEnrollment(string $role, string $balance): array
    {
        $user = User::factory()->create(['status' => $role === 'student' ? User::StatusActive : 'pending']);
        $user->assignRole($role);
        $student = StudentProfile::factory()->for($user, 'user')->create(['current_balance' => $balance]);
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $student->id,
            'term_id' => $term->id,
        ]);

        return [$student->load('user'), $enrollment];
    }

    private function pendingNote(
        StudentProfile $student,
        Enrollment $enrollment,
        string $amount = '1500.00',
        ?string $dueDate = null,
    ): PromissoryNote {
        return PromissoryNote::query()->create([
            'student_profile_id' => $student->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'amount' => $amount,
            'due_date' => $dueDate ?? now()->addDays(10)->toDateString(),
            'status' => PromissoryNote::StatusPending,
            'reason' => 'Temporary financial emergency.',
            'requested_by' => $student->user_id,
            'requested_at' => now(),
            'request_source' => PromissoryNote::SourceStudent,
        ]);
    }

    private function accountingUser(): User
    {
        Permission::findOrCreate('approve-promissory-notes');
        $user = User::factory()->create();
        $user->givePermissionTo('approve-promissory-notes');

        return $user;
    }
}
