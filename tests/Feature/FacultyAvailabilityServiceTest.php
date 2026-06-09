<?php

namespace Tests\Feature;

use App\Actions\Scheduling\FacultyAvailabilityService;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FacultyAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_must_close_before_scheduling_starts(): void
    {
        $registrar = $this->userWithPermission('review-lock-faculty-availability');
        $term = $this->readyTerm([
            'scheduling_starts_at' => now()->addDays(3),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Availability period must close on or before scheduling starts.');

        app(FacultyAvailabilityService::class)->preparePeriodData([
            'term_id' => $term->id,
            'opens_at' => now()->addDay(),
            'closes_at' => now()->addDays(4),
        ], $registrar);
    }

    public function test_faculty_can_submit_valid_windows_during_open_period(): void
    {
        $registrar = $this->userWithPermission('review-lock-faculty-availability');
        $faculty = $this->userWithPermission('submit-faculty-availability');
        $period = $this->openPeriod($registrar);

        $submission = app(FacultyAvailabilityService::class)->submitAvailability([
            'availability_period_id' => $period->id,
            'windows' => [
                [
                    'day_of_week' => 1,
                    'starts_at' => '08:00',
                    'ends_at' => '12:00',
                    'notes' => 'Morning availability',
                ],
                [
                    'day_of_week' => 3,
                    'starts_at' => '13:00:00',
                    'ends_at' => '16:00:00',
                    'notes' => null,
                ],
            ],
        ], $faculty);

        $this->assertSame(FacultyAvailabilitySubmission::StatusSubmitted, $submission->status);
        $this->assertSame($period->term_id, $submission->term_id);
        $this->assertSame($faculty->id, $submission->faculty_id);
        $this->assertSame(1, $submission->version);
        $this->assertNotNull($submission->submitted_at);
        $this->assertCount(2, $submission->windows);
        $this->assertDatabaseHas('faculty_availability_windows', [
            'submission_id' => $submission->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
        ]);
    }

    public function test_invalid_or_overlapping_windows_are_rejected(): void
    {
        $registrar = $this->userWithPermission('review-lock-faculty-availability');
        $faculty = $this->userWithPermission('submit-faculty-availability');
        $period = $this->openPeriod($registrar);

        try {
            app(FacultyAvailabilityService::class)->submitAvailability([
                'availability_period_id' => $period->id,
                'windows' => [
                    [
                        'day_of_week' => 2,
                        'starts_at' => '10:00:00',
                        'ends_at' => '09:00:00',
                    ],
                    [
                        'day_of_week' => 3,
                        'starts_at' => '09:00:00',
                        'ends_at' => '11:00:00',
                    ],
                    [
                        'day_of_week' => 3,
                        'starts_at' => '10:30:00',
                        'ends_at' => '12:00:00',
                    ],
                ],
            ], $faculty);

            $this->fail('Expected invalid and overlapping windows to be rejected.');
        } catch (ValidationException $exception) {
            $messages = $exception->validator->errors()->all();

            $this->assertContains('Availability window end time must be after the start time.', $messages);
            $this->assertContains('Availability windows cannot overlap on the same day.', $messages);
        }
    }

    public function test_duplicate_submitted_or_locked_submission_is_rejected(): void
    {
        $registrar = $this->userWithPermission('review-lock-faculty-availability');
        $faculty = $this->userWithPermission('submit-faculty-availability');
        $period = $this->openPeriod($registrar);

        app(FacultyAvailabilityService::class)->submitAvailability($this->submissionPayload($period), $faculty);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Faculty already submitted availability for this term.');

        app(FacultyAvailabilityService::class)->submitAvailability($this->submissionPayload($period), $faculty);
    }

    public function test_registrar_locks_submitted_availability_for_solver_input(): void
    {
        $registrar = $this->userWithPermission('review-lock-faculty-availability');
        $faculty = $this->userWithPermission('submit-faculty-availability');
        $period = $this->openPeriod($registrar);
        $submission = app(FacultyAvailabilityService::class)->submitAvailability($this->submissionPayload($period), $faculty);

        $locked = app(FacultyAvailabilityService::class)->lockSubmission($submission, $registrar);

        $this->assertSame(FacultyAvailabilitySubmission::StatusLocked, $locked->status);
        $this->assertSame($registrar->id, $locked->approved_by);
        $this->assertNotNull($locked->locked_at);
        $this->assertNotNull($locked->approved_at);
    }

    private function readyTerm(array $attributes = []): Term
    {
        return Term::factory()->create([
            'term_name' => '1st Semester AY 2026',
            'term_start_date' => now()->addWeeks(2)->toDateString(),
            'term_end_date' => now()->addMonths(5)->toDateString(),
            'scheduling_starts_at' => now()->addDays(7),
            ...$attributes,
        ]);
    }

    private function openPeriod(User $registrar): FacultyAvailabilityPeriod
    {
        $term = $this->readyTerm([
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
    private function submissionPayload(FacultyAvailabilityPeriod $period): array
    {
        return [
            'availability_period_id' => $period->id,
            'windows' => [
                [
                    'day_of_week' => 1,
                    'starts_at' => '08:00:00',
                    'ends_at' => '12:00:00',
                ],
            ],
        ];
    }

    private function userWithPermission(string $permission): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate($permission));

        return $user;
    }
}
