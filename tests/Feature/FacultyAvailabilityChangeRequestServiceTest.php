<?php

namespace Tests\Feature;

use App\Actions\Scheduling\FacultyAvailabilityChangeRequestService;
use App\Actions\Scheduling\FacultyAvailabilityService;
use App\Models\FacultyAvailabilityChangeRequest;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FacultyAvailabilityChangeRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_faculty_can_request_change_against_latest_locked_submission(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, ['review-lock-faculty-availability']);
        $faculty = $this->staffUser(User::StaffRoleFaculty, ['submit-faculty-availability']);
        $submission = $this->lockedSubmission($registrar, $faculty);

        $request = app(FacultyAvailabilityChangeRequestService::class)->requestChange($faculty, $submission, [
            'reason' => 'Clinic schedule changed after the deadline.',
            'requested_windows' => [
                [
                    'day_of_week' => 2,
                    'starts_at' => '09:00',
                    'ends_at' => '12:00',
                    'notes' => 'Updated Tuesday availability.',
                ],
            ],
        ]);

        $this->assertSame(FacultyAvailabilityChangeRequest::StatusPending, $request->status);
        $this->assertSame($submission->id, $request->submission_id);
        $this->assertSame($submission->term_id, $request->term_id);
        $this->assertSame($faculty->id, $request->faculty_id);
        $this->assertSame('Clinic schedule changed after the deadline.', $request->reason);
        $this->assertSame('08:00:00', $request->source_windows[0]['starts_at']);
        $this->assertSame('09:00:00', $request->requested_windows[0]['starts_at']);
        $this->assertNull($request->creates_submission_id);
    }

    public function test_registrar_approval_creates_new_locked_revision_without_mutating_source(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, ['review-lock-faculty-availability']);
        $faculty = $this->staffUser(User::StaffRoleFaculty, ['submit-faculty-availability']);
        $submission = $this->lockedSubmission($registrar, $faculty);
        $request = app(FacultyAvailabilityChangeRequestService::class)->requestChange($faculty, $submission, $this->changePayload());

        $approved = app(FacultyAvailabilityChangeRequestService::class)->approve($request, $registrar, 'Approved for regenerated schedule input.');
        $revision = FacultyAvailabilitySubmission::query()->with('windows')->findOrFail($approved->creates_submission_id);

        $this->assertSame(FacultyAvailabilityChangeRequest::StatusApproved, $approved->status);
        $this->assertSame($registrar->id, $approved->reviewed_by);
        $this->assertSame('Approved for regenerated schedule input.', $approved->review_note);
        $this->assertSame(FacultyAvailabilitySubmission::StatusLocked, $revision->status);
        $this->assertSame(2, $revision->version);
        $this->assertSame($submission->id, $revision->parent_submission_id);
        $this->assertSame($request->reason, $revision->change_reason);
        $this->assertSame('09:00:00', $revision->windows->first()->starts_at);
        $this->assertSame(FacultyAvailabilitySubmission::StatusLocked, $submission->refresh()->status);
        $this->assertSame(1, $submission->version);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'faculty_availability_change_approved',
            'subject_type' => FacultyAvailabilityChangeRequest::class,
            'subject_id' => $approved->id,
        ]);
    }

    public function test_rejection_records_review_without_creating_revision(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, ['review-lock-faculty-availability']);
        $faculty = $this->staffUser(User::StaffRoleFaculty, ['submit-faculty-availability']);
        $submission = $this->lockedSubmission($registrar, $faculty);
        $request = app(FacultyAvailabilityChangeRequestService::class)->requestChange($faculty, $submission, $this->changePayload());

        $rejected = app(FacultyAvailabilityChangeRequestService::class)->reject($request, $registrar, 'Schedule already committed; file schedule change instead.');

        $this->assertSame(FacultyAvailabilityChangeRequest::StatusRejected, $rejected->status);
        $this->assertSame($registrar->id, $rejected->reviewed_by);
        $this->assertSame('Schedule already committed; file schedule change instead.', $rejected->review_note);
        $this->assertNull($rejected->creates_submission_id);
        $this->assertSame(1, FacultyAvailabilitySubmission::query()->where('faculty_id', $faculty->id)->count());
    }

    public function test_request_rejects_non_owner_invalid_windows_duplicate_and_stale_submission(): void
    {
        $registrar = $this->staffUser(User::StaffRoleRegistrar, ['review-lock-faculty-availability']);
        $faculty = $this->staffUser(User::StaffRoleFaculty, ['submit-faculty-availability']);
        $otherFaculty = $this->staffUser(User::StaffRoleFaculty, ['submit-faculty-availability']);
        $submission = $this->lockedSubmission($registrar, $faculty);
        $service = app(FacultyAvailabilityChangeRequestService::class);

        try {
            $service->requestChange($otherFaculty, $submission, $this->changePayload());
            $this->fail('Expected non-owner request to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Faculty may request changes only for their own availability.', $exception->getMessage());
        }

        try {
            $service->requestChange($faculty, $submission, [
                'reason' => 'Bad windows.',
                'requested_windows' => [
                    ['day_of_week' => 1, 'starts_at' => '11:00:00', 'ends_at' => '10:00:00'],
                    ['day_of_week' => 2, 'starts_at' => '09:00:00', 'ends_at' => '11:00:00'],
                    ['day_of_week' => 2, 'starts_at' => '10:30:00', 'ends_at' => '12:00:00'],
                ],
            ]);
            $this->fail('Expected invalid windows to be rejected.');
        } catch (ValidationException $exception) {
            $messages = $exception->validator->errors()->all();

            $this->assertContains('Availability window end time must be after the start time.', $messages);
            $this->assertContains('Availability windows cannot overlap on the same day.', $messages);
        }

        $pending = $service->requestChange($faculty, $submission, $this->changePayload());

        try {
            $service->requestChange($faculty, $submission, $this->changePayload('Second request.'));
            $this->fail('Expected duplicate pending request to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('A pending availability change request already exists for this submission.', $exception->getMessage());
        }

        $service->approve($pending, $registrar);

        try {
            $service->requestChange($faculty, $submission, $this->changePayload('Stale request.'));
            $this->fail('Expected stale source submission to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Availability change requests must target the latest faculty availability version.', $exception->getMessage());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::staffRoleNames() as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    private function lockedSubmission(User $registrar, User $faculty): FacultyAvailabilitySubmission
    {
        $period = $this->openPeriod($registrar);
        $submission = app(FacultyAvailabilityService::class)->submitAvailability([
            'availability_period_id' => $period->id,
            'windows' => [
                [
                    'day_of_week' => 1,
                    'starts_at' => '08:00:00',
                    'ends_at' => '12:00:00',
                    'notes' => 'Original availability.',
                ],
            ],
        ], $faculty);

        return app(FacultyAvailabilityService::class)->lockSubmission($submission, $registrar);
    }

    private function openPeriod(User $registrar): FacultyAvailabilityPeriod
    {
        $term = Term::factory()->create([
            'term_name' => '1st Semester AY 2026',
            'term_start_date' => now()->addWeeks(2)->toDateString(),
            'term_end_date' => now()->addMonths(5)->toDateString(),
            'scheduling_starts_at' => now()->addDays(2),
        ]);

        return FacultyAvailabilityPeriod::factory()->create([
            'term_id' => $term->id,
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addHour(),
            'status' => FacultyAvailabilityPeriod::StatusOpen,
            'created_by' => $registrar->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function changePayload(string $reason = 'Clinic schedule changed after the deadline.'): array
    {
        return [
            'reason' => $reason,
            'requested_windows' => [
                [
                    'day_of_week' => 2,
                    'starts_at' => '09:00:00',
                    'ends_at' => '12:00:00',
                    'notes' => 'Updated availability.',
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffUser(string $roleName, array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create();
        $user->assignRole($roleName);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
