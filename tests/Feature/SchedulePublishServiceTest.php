<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SchedulePublishService;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultyAvailabilityWindow;
use App\Models\FacultySubjectEligibility;
use App\Models\Program;
use App\Models\CandidateScheduleRow;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
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

class SchedulePublishServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_publish_creates_official_meetings_and_faculty_assignment(): void
    {
        $registrar = $this->registrar();
        $faculty = User::factory()->create();
        [$run, $section, $subject] = $this->scheduleRunWithDraftRow($registrar, $faculty);

        $publishedRun = app(SchedulePublishService::class)->publish(
            $run,
            $registrar,
            '  Ready for posting.  ',
        );

        $meeting = SectionMeeting::query()->where('schedule_generation_run_id', $run->id)->first();
        $properties = $this->activityProperties($publishedRun);

        $this->assertNotNull($meeting);
        $this->assertSame(ScheduleGenerationRun::StatusPublished, $publishedRun->status);
        $this->assertSame($registrar->id, $publishedRun->committed_by);
        $this->assertSame($registrar->id, $publishedRun->published_by);
        $this->assertNotNull($publishedRun->committed_at);
        $this->assertNotNull($publishedRun->published_at);
        $this->assertSame('Ready for posting.', $publishedRun->publish_note);
        $this->assertFalse((bool) $publishedRun->emergency_published);
        $this->assertSame(ScheduleGenerationRun::StatusPublished, $properties['status_after']);
        $this->assertSame(1, $properties['published_meetings']);
        $this->assertDatabaseHas('section_teacher', [
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'user_id' => $faculty->id,
        ]);
    }

    public function test_non_registrar_cannot_publish_reviewed_run(): void
    {
        $actor = User::factory()->create();
        [$run] = $this->scheduleRunWithDraftRow($this->registrar(), User::factory()->create());

        $this->expectException(AuthorizationException::class);

        app(SchedulePublishService::class)->publish($run, $actor);
    }

    public function test_emergency_publication_is_not_allowed(): void
    {
        $registrar = $this->registrar();
        [$run] = $this->scheduleRunWithDraftRow($registrar, User::factory()->create());

        $this->expectException(AuthorizationException::class);

        app(SchedulePublishService::class)->publish($run, $registrar, 'Bypass', emergency: true);
    }

    public function test_publish_rejects_conflicted_draft_rows(): void
    {
        $registrar = $this->registrar();
        [$run] = $this->scheduleRunWithDraftRow($registrar, User::factory()->create(), [
            'status' => CandidateScheduleRow::StatusConflict,
        ]);

        try {
            app(SchedulePublishService::class)->publish($run, $registrar);
            $this->fail('Expected conflicted draft rows to block schedule publication.');
        } catch (ValidationException) {
            $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->refresh()->status);
            $this->assertSame(0, SectionMeeting::query()->where('schedule_generation_run_id', $run->id)->count());
        }
    }

    public function test_publish_supersedes_prior_published_run_for_same_term(): void
    {
        $registrar = $this->registrar();
        $faculty = User::factory()->create();
        [$priorRun, $section, $subject, $deliveryGroup] = $this->scheduleRunWithDraftRow($registrar, $faculty);

        app(SchedulePublishService::class)->publish($priorRun, $registrar);

        $replacementRun = $this->runForExistingSection(
            $registrar,
            $faculty,
            $section,
            $subject,
            $deliveryGroup,
            startsAt: '10:00:00',
            endsAt: '11:00:00',
        );

        app(SchedulePublishService::class)->publish($replacementRun, $registrar, 'Replacement version.');

        $this->assertSame(ScheduleGenerationRun::StatusSuperseded, $priorRun->refresh()->status);
        $this->assertSame(ScheduleGenerationRun::StatusPublished, $replacementRun->refresh()->status);
        $this->assertSame(1, SectionMeeting::query()->where('schedule_generation_run_id', $priorRun->id)->count());
        $this->assertSame(1, SectionMeeting::query()->where('schedule_generation_run_id', $replacementRun->id)->count());
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
     * @return array{0: ScheduleGenerationRun, 1: Section, 2: Subject, 3: SectionDeliveryGroup}
     */
    private function scheduleRunWithDraftRow(
        User $registrar,
        User $faculty,
        array $draftRowAttributes = [],
    ): array {
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $section = Section::factory()->for($term)->for($program)->create();
        $deliveryGroup = SectionDeliveryGroup::factory()->create([
            'section_id' => $section->id,
            'modality' => 'on_site',
            'capacity' => 30,
            'assigned_count' => 25,
            'room_required' => true,
            'room' => 'R-101',
            'status' => SectionDeliveryGroup::StatusActive,
        ]);
        $subject = Subject::factory()->create();

        FacultySubjectEligibility::factory()->create([
            'faculty_id' => $faculty->id,
            'subject_id' => $subject->id,
            'term_id' => null,
        ]);
        $this->createFacultyAvailability($term, $faculty);

        $run = $this->runForExistingSection($registrar, $faculty, $section, $subject, $deliveryGroup, $draftRowAttributes);

        return [$run, $section, $subject, $deliveryGroup];
    }

    /**
     * @param  array<string, mixed>  $draftRowAttributes
     */
    private function runForExistingSection(
        User $registrar,
        User $faculty,
        Section $section,
        Subject $subject,
        SectionDeliveryGroup $deliveryGroup,
        array $draftRowAttributes = [],
        string $startsAt = '08:00:00',
        string $endsAt = '09:00:00',
    ): ScheduleGenerationRun {
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $section->term_id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'requested_by' => $registrar->id,
            'generated_at' => now(),
            'constraint_summary' => [],
        ]);

        DB::table('candidate_schedule_rows')->insert([
            'generation_run_id' => $run->id,
            'section_id' => $section->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room' => 'R-101',
            'day_of_week' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'modality' => 'on_site',
            'status' => CandidateScheduleRow::StatusOk,
            'created_at' => now(),
            'updated_at' => now(),
            ...$draftRowAttributes,
        ]);

        return $run;
    }

    private function createFacultyAvailability(Term $term, User $faculty): void
    {
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
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(ScheduleGenerationRun $run): array
    {
        $activity = DB::table('activity_log')
            ->where('subject_type', ScheduleGenerationRun::class)
            ->where('subject_id', $run->id)
            ->where('event', 'schedule_generation_run_published')
            ->first();

        $this->assertNotNull($activity);

        return json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
    }
}
