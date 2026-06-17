<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleChangeLifecycleService;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\ScheduleChange;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Support\Scheduling\ScheduleChangePayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ScheduleChangeLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_records_authorized_lifecycle_decision(): void
    {
        $academicHead = $this->userWithPermission('authorize-overrides');
        [$scheduleChange] = $this->scheduleChangeFixtures();

        app(ScheduleChangeLifecycleService::class)->approve($scheduleChange, $academicHead);

        $scheduleChange->refresh();
        $properties = $this->activityProperties($scheduleChange, 'schedule_change_approved');

        $this->assertSame(ScheduleChange::StatusApproved, $scheduleChange->status);
        $this->assertSame($academicHead->id, $scheduleChange->approved_by);
        $this->assertSame(ScheduleChange::StatusApproved, $properties['status_after']);
        $this->assertSame($scheduleChange->term_id, $properties['term_id']);
    }

    public function test_apply_updates_official_meeting_from_normalized_payload(): void
    {
        $registrar = $this->userWithPermission('manage-schedules');
        [$scheduleChange, $meeting] = $this->scheduleChangeFixtures([
            'status' => ScheduleChange::StatusApproved,
            'approved_by' => User::factory()->create()->id,
        ]);

        app(ScheduleChangeLifecycleService::class)->apply($scheduleChange, $registrar);

        $scheduleChange->refresh();
        $meeting->refresh();
        $properties = $this->activityProperties($scheduleChange, 'schedule_change_applied');

        $this->assertSame(ScheduleChange::StatusApplied, $scheduleChange->status);
        $this->assertNotNull($scheduleChange->applied_at);
        $this->assertSame('RUT 202', $meeting->room);
        $this->assertSame(3, $meeting->day_of_week);
        $this->assertSame('10:00', (string) $meeting->starts_at);
        $this->assertSame('11:30', (string) $meeting->ends_at);
        $this->assertNull($meeting->availability_override_reason);
        $this->assertSame(ScheduleChange::StatusApplied, $properties['status_after']);
    }

    public function test_apply_records_schedule_change_reason_as_availability_override_evidence_when_needed(): void
    {
        $registrar = $this->userWithPermission('manage-schedules');
        [$scheduleChange, $meeting] = $this->scheduleChangeFixtures([
            'status' => ScheduleChange::StatusApproved,
            'approved_by' => User::factory()->create()->id,
            'reason' => 'Registrar confirmed a one-time faculty availability exception.',
        ]);

        $scheduleChange->forceFill([
            'new_payload' => [
                'faculty_id' => $meeting->faculty_id,
                'room' => 'RUT 202',
                'day_of_week' => 3,
                'starts_at' => '15:00',
                'ends_at' => '16:00',
                'modality' => 'on_site',
            ],
        ])->save();

        app(ScheduleChangeLifecycleService::class)->apply($scheduleChange, $registrar);

        $meeting->refresh();

        $this->assertSame('Registrar confirmed a one-time faculty availability exception.', $meeting->availability_override_reason);
        $this->assertSame($registrar->id, $meeting->availability_override_by);
        $this->assertNotNull($meeting->availability_override_at);
        $this->assertSame('outside_availability_window', $meeting->availability_override_payload['type']);
        $this->assertSame($scheduleChange->id, $meeting->availability_override_payload['schedule_change_id']);
    }

    public function test_schedule_change_lifecycle_requires_matching_permissions(): void
    {
        $actor = User::factory()->create();
        [$scheduleChange] = $this->scheduleChangeFixtures();

        $this->expectException(AuthorizationException::class);

        app(ScheduleChangeLifecycleService::class)->approve($scheduleChange, $actor);
    }

    public function test_only_approved_schedule_changes_can_be_applied(): void
    {
        $registrar = $this->userWithPermission('manage-schedules');
        [$scheduleChange] = $this->scheduleChangeFixtures();

        $this->expectException(ValidationException::class);

        app(ScheduleChangeLifecycleService::class)->apply($scheduleChange, $registrar);
    }

    public function test_schedule_change_status_options_match_lifecycle_contract(): void
    {
        $this->assertSame([
            ScheduleChange::StatusProposed => 'Proposed',
            ScheduleChange::StatusApproved => 'Approved',
            ScheduleChange::StatusApplied => 'Applied',
            ScheduleChange::StatusRejected => 'Rejected',
        ], ScheduleChange::statusOptions());

        $this->assertSame([
            'warning' => ScheduleChange::StatusProposed,
            'info' => ScheduleChange::StatusApproved,
            'success' => ScheduleChange::StatusApplied,
            'danger' => ScheduleChange::StatusRejected,
        ], ScheduleChange::statusColors());
    }

    private function userWithPermission(string $permission): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate($permission));

        return $user;
    }

    /**
     * @param  array<string, mixed>  $scheduleChangeAttributes
     * @return array{0: ScheduleChange, 1: SectionMeeting}
     */
    private function scheduleChangeFixtures(array $scheduleChangeAttributes = []): array
    {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $section = Section::factory()->for($term)->for($program)->create();
        $subject = Subject::factory()->create();
        $registrar = User::factory()->create();
        $faculty = User::factory()->create();
        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);
        $this->createFacultyAvailability($term, $faculty, dayOfWeek: 3, startsAt: '08:00:00', endsAt: '12:00:00');

        $meeting = SectionMeeting::query()->create([
            'term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '09:30',
            'modality' => 'on_site',
            'committed_by' => $registrar->id,
            'committed_at' => now(),
        ]);

        $scheduleChange = ScheduleChange::query()->create([
            'term_id' => $term->id,
            'section_meeting_id' => $meeting->id,
            'status' => ScheduleChange::StatusProposed,
            'old_payload' => ScheduleChangePayload::fromSectionMeeting($meeting),
            'new_payload' => [
                'faculty_id' => $faculty->id,
                'room' => 'RUT 202',
                'day_of_week' => 3,
                'starts_at' => '10:00',
                'ends_at' => '11:30',
                'modality' => 'on_site',
            ],
            'reason' => 'Room conflict repair.',
            'requested_by' => $registrar->id,
            ...$scheduleChangeAttributes,
        ]);

        return [$scheduleChange, $meeting];
    }

    private function createFacultyAvailability(
        Term $term,
        User $faculty,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
    ): void {
        $period = FacultyAvailabilityPeriod::query()->firstOrCreate(
            ['term_id' => $term->id],
            [
                'opens_at' => now()->subDay(),
                'closes_at' => now()->addDays(7),
                'status' => 'open',
                'created_by' => User::factory()->create()->id,
                'locked_at' => null,
            ],
        );

        $submission = FacultyAvailabilitySubmission::factory()->create([
            'term_id' => $term->id,
            'availability_period_id' => $period->id,
            'faculty_id' => $faculty->id,
            'status' => FacultyAvailabilitySubmission::StatusLocked,
            'version' => 1,
            'locked_at' => now(),
        ]);

        FacultyAvailabilityWindow::factory()->create([
            'submission_id' => $submission->id,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(ScheduleChange $scheduleChange, string $event): array
    {
        $activity = DB::table('activity_log')
            ->where('subject_type', ScheduleChange::class)
            ->where('subject_id', $scheduleChange->id)
            ->where('event', $event)
            ->first();

        $this->assertNotNull($activity);

        return json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
    }
}
