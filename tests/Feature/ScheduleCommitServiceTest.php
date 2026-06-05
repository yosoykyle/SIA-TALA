<?php

namespace Tests\Feature;

use App\Actions\Scheduling\ScheduleCommitService;
use App\Models\Program;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ScheduleCommitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_commit_creates_official_meetings_and_faculty_assignment(): void
    {
        $registrar = $this->registrar();
        $faculty = User::factory()->create();
        [$run, $section, $subject] = $this->scheduleRunWithDraftRow($registrar, $faculty);

        app(ScheduleCommitService::class)->commit($run, $registrar);

        $run->refresh();
        $meeting = SectionMeeting::query()->where('schedule_generation_run_id', $run->id)->first();
        $properties = $this->activityProperties($run);

        $this->assertNotNull($meeting);
        $this->assertSame(ScheduleGenerationRun::StatusCommitted, $run->status);
        $this->assertSame($registrar->id, $run->committed_by);
        $this->assertNotNull($run->committed_at);
        $this->assertSame($section->id, $meeting->section_id);
        $this->assertSame($subject->id, $meeting->subject_id);
        $this->assertSame($faculty->id, $meeting->faculty_id);
        $this->assertDatabaseHas('section_teacher', [
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'user_id' => $faculty->id,
        ]);
        $this->assertSame(ScheduleGenerationRun::StatusCommitted, $properties['status_after']);
        $this->assertSame(1, $properties['committed_meetings']);
    }

    public function test_commit_rejects_conflicted_draft_rows(): void
    {
        $registrar = $this->registrar();
        [$run] = $this->scheduleRunWithDraftRow($registrar, User::factory()->create(), [
            'status' => 'conflict',
        ]);

        try {
            app(ScheduleCommitService::class)->commit($run, $registrar);
            $this->fail('Expected conflicted draft rows to block schedule commit.');
        } catch (ValidationException) {
            $this->assertSame(ScheduleGenerationRun::StatusGenerated, $run->refresh()->status);
            $this->assertSame(0, SectionMeeting::query()->where('schedule_generation_run_id', $run->id)->count());
        }
    }

    public function test_commit_requires_manage_schedules_permission(): void
    {
        $actor = User::factory()->create();
        [$run] = $this->scheduleRunWithDraftRow($this->registrar(), User::factory()->create());

        $this->expectException(AuthorizationException::class);

        app(ScheduleCommitService::class)->commit($run, $actor);
    }

    public function test_non_eligible_schedule_run_status_cannot_be_committed(): void
    {
        $registrar = $this->registrar();
        [$run] = $this->scheduleRunWithDraftRow($registrar, User::factory()->create(), runAttributes: [
            'status' => ScheduleGenerationRun::StatusBlocked,
        ]);

        $this->expectException(ValidationException::class);

        app(ScheduleCommitService::class)->commit($run, $registrar);
    }

    public function test_schedule_run_status_options_match_lifecycle_contract(): void
    {
        $this->assertSame([
            ScheduleGenerationRun::StatusGenerated => 'Generated',
            ScheduleGenerationRun::StatusDraft => 'Draft',
            ScheduleGenerationRun::StatusUnderReview => 'Under Review',
            ScheduleGenerationRun::StatusBlocked => 'Blocked',
            ScheduleGenerationRun::StatusCommitted => 'Committed',
            ScheduleGenerationRun::StatusAbandoned => 'Abandoned',
            ScheduleGenerationRun::StatusSuperseded => 'Superseded',
        ], ScheduleGenerationRun::statusOptions());

        $this->assertSame([
            ScheduleGenerationRun::StatusGenerated,
            ScheduleGenerationRun::StatusUnderReview,
        ], ScheduleGenerationRun::committableStatuses());
    }

    private function registrar(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-schedules'));

        return $registrar;
    }

    /**
     * @param  array<string, mixed>  $draftRowAttributes
     * @param  array<string, mixed>  $runAttributes
     * @return array{0: ScheduleGenerationRun, 1: Section, 2: Subject}
     */
    private function scheduleRunWithDraftRow(
        User $registrar,
        User $faculty,
        array $draftRowAttributes = [],
        array $runAttributes = [],
    ): array {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $section = Section::factory()->for($term)->for($program)->create();
        $subject = Subject::factory()->create();

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusGenerated,
            'requested_by' => $registrar->id,
            'generated_at' => now(),
            'constraint_summary' => [],
            ...$runAttributes,
        ]);

        DB::table('schedule_draft_rows')->insert([
            'generation_run_id' => $run->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'R-101',
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'modality' => 'on_site',
            'status' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
            ...$draftRowAttributes,
        ]);

        return [$run, $section, $subject];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(ScheduleGenerationRun $run): array
    {
        $activity = DB::table('activity_log')
            ->where('subject_type', ScheduleGenerationRun::class)
            ->where('subject_id', $run->id)
            ->where('event', 'schedule_generation_run_committed')
            ->first();

        $this->assertNotNull($activity);

        return json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
    }
}
