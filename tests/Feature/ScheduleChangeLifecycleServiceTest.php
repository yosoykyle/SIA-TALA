<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleChangeLifecycleService;
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
        $this->assertSame(ScheduleChange::StatusApplied, $properties['status_after']);
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
