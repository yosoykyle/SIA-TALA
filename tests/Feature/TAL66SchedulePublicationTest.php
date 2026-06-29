<?php

namespace Tests\Feature;

use App\Actions\Scheduling\SchedulePublishService;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\SectionMeetings\Pages\ListSectionMeetings;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Models\CandidateScheduleRow;
use App\Models\CourseComponent;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL66SchedulePublicationTest extends TestCase
{
    use DatabaseTransactions;

    private SchedulePublishService $publisher;

    private User $faculty;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->publisher = app(SchedulePublishService::class);
        $this->faculty = $this->staff(User::StaffRoleFaculty);
        $this->room = Room::factory()->create();
    }

    public function test_authorized_registrar_publishes_clean_candidate_mapping_and_audit_metadata(): void
    {
        $publishedAt = Carbon::parse('2026-06-29 09:30:00');
        Carbon::setTestNow($publishedAt);

        try {
            $registrar = $this->staff(User::StaffRoleRegistrar);
            $term = Term::factory()->create();
            $run = $this->scheduleRun($term);
            $demand = $this->demand($term, TermOffering::ModalityOnline);
            $candidate = $this->candidate($run, $demand, roomId: null);

            $published = $this->publisher->publish($run, $registrar, '  Reviewed for publication.  ');
            $meeting = SectionMeeting::query()->sole();

            $this->assertSame(ScheduleGenerationRun::StatusPublished, $published->status);
            $this->assertSame($registrar->id, $published->published_by);
            $this->assertTrue($published->published_at->equalTo($publishedAt));
            $this->assertSame(1, $published->publication_version);
            $this->assertSame('Reviewed for publication.', $published->publication_note);
            $this->assertSame($run->id, $meeting->schedule_run_id);
            $this->assertSame($demand->id, $meeting->scheduling_demand_id);
            $this->assertSame($candidate->meeting_sequence, $meeting->meeting_sequence);
            $this->assertSame($candidate->faculty_user_id, $meeting->faculty_user_id);
            $this->assertNull($meeting->room_id);
            $this->assertSame($candidate->day_of_week, $meeting->day_of_week);
            $this->assertSame($candidate->starts_at, $meeting->starts_at);
            $this->assertSame($candidate->ends_at, $meeting->ends_at);
            $this->assertSame(TermOffering::ModalityOnline, $meeting->modality);
            $this->assertSame(SectionMeeting::StateActive, $meeting->state);
            $this->assertTrue($meeting->published_at->equalTo($publishedAt));
            $this->assertSame(1, $run->candidateRows()->count());

            $activity = DB::table('activity_log')
                ->where('subject_type', ScheduleGenerationRun::class)
                ->where('subject_id', $run->id)
                ->sole();
            $properties = json_decode($activity->properties, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('schedule_generation_run_published', $activity->event);
            $this->assertSame($registrar->id, (int) $activity->causer_id);
            $this->assertSame(1, $properties['publication_version']);
            $this->assertSame(1, $properties['published_meetings']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_system_super_admin_is_authorized_to_publish(): void
    {
        $systemSuperAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $term = Term::factory()->create();
        $run = $this->scheduleRun($term);
        $this->candidate($run, $this->demand($term));

        $this->assertTrue(Gate::forUser($systemSuperAdmin)->allows('publish', $run));

        $published = $this->publisher->publish($run, $systemSuperAdmin);

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $published->status);
        $this->assertSame($systemSuperAdmin->id, $published->published_by);
    }

    public function test_academic_head_and_unauthorized_staff_cannot_publish(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->faculty;
        $term = Term::factory()->create();
        $run = $this->scheduleRun($term);
        $this->candidate($run, $this->demand($term));

        $this->assertFalse(Gate::forUser($academicHead)->allows('publish', $run));
        $this->assertFalse(Gate::forUser($faculty)->allows('publish', $run));

        foreach ([$academicHead, $faculty] as $unauthorizedUser) {
            try {
                $this->publisher->publish($run, $unauthorizedUser);
                $this->fail('Unauthorized publication was not rejected.');
            } catch (AuthorizationException) {
                $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->fresh()->status);
                $this->assertSame(0, SectionMeeting::query()->count());
            }
        }
    }

    public function test_conflicts_and_nonempty_violations_block_publication_without_writes(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $conflictRun = $this->scheduleRun($term);
        $this->candidate($conflictRun, $this->demand($term), CandidateScheduleRow::StatusConflict);

        $this->assertPublicationRejected($conflictRun, $registrar, 'candidate_schedule_rows');

        $violationRun = $this->scheduleRun($term);
        $this->candidate(
            $violationRun,
            $this->demand($term),
            CandidateScheduleRow::StatusOk,
            violations: [['key' => 'faculty_overlap', 'message' => 'Blocking overlap.']],
        );

        $this->assertPublicationRejected($violationRun, $registrar, 'candidate_schedule_rows');
        $this->assertSame(0, SectionMeeting::query()->count());
    }

    public function test_empty_candidates_and_wrong_run_statuses_block_publication(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $emptyRun = $this->scheduleRun($term);

        $this->assertPublicationRejected($emptyRun, $registrar, 'candidate_schedule_rows');

        foreach ([
            ScheduleGenerationRun::StatusQueued,
            ScheduleGenerationRun::StatusBlocked,
            ScheduleGenerationRun::StatusFailed,
            ScheduleGenerationRun::StatusPublished,
            ScheduleGenerationRun::StatusSuperseded,
        ] as $status) {
            $run = $this->scheduleRun($term, $status);
            $this->candidate($run, $this->demand($term));
            $this->assertPublicationRejected($run, $registrar, 'status');
        }

        $this->assertSame(0, SectionMeeting::query()->count());
    }

    public function test_warning_only_candidates_publish_through_confirmed_filament_action_with_note(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $run = $this->scheduleRun($term);
        $this->candidate(
            $run,
            $this->demand($term),
            CandidateScheduleRow::StatusWarning,
            warnings: [['key' => 'late_slot', 'message' => 'Late class slot.']],
        );

        $component = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $run->getRouteKey()])
            ->assertActionVisible('publishSchedule')
            ->assertActionHasLabel('publishSchedule', 'Publish Schedule');

        $action = $component->instance()->getAction('publishSchedule');

        $this->assertInstanceOf(Action::class, $action);
        $this->assertTrue($action->isConfirmationRequired());
        $this->assertStringContainsString('1 candidate assignment', (string) $action->getModalDescription());
        $this->assertStringContainsString('1 warning row', (string) $action->getModalDescription());
        $this->assertStringContainsString('0 conflict or violation rows', (string) $action->getModalDescription());

        $action
            ->data(['publication_note' => 'Accepted advisory scheduling warning.'])
            ->call();

        $component->assertNotified('Schedule published');

        $run->refresh();

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $run->status);
        $this->assertSame('Accepted advisory scheduling warning.', $run->publication_note);
        $this->assertSame(1, SectionMeeting::query()->count());
    }

    public function test_prior_published_run_is_superseded_while_history_remains_and_active_scope_resolves_current_version(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $priorRun = $this->scheduleRun($term, ScheduleGenerationRun::StatusPublished, [
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
            'publication_version' => 1,
        ]);
        $priorDemand = $this->demand($term);
        $historicalMeeting = $this->officialMeeting($priorRun, $priorDemand, now()->subDay());
        $newRun = $this->scheduleRun($term);
        $newDemand = $this->demand($term, TermOffering::ModalityOnline);
        $this->candidate($newRun, $newDemand, roomId: null);

        $published = $this->publisher->publish($newRun, $registrar);

        $this->assertSame(ScheduleGenerationRun::StatusSuperseded, $priorRun->fresh()->status);
        $this->assertSame(2, $published->publication_version);
        $this->assertModelExists($historicalMeeting);
        $this->assertSame(2, SectionMeeting::query()->count());
        $this->assertSame(
            [$published->id],
            SectionMeeting::query()->activeOfficial()->pluck('schedule_run_id')->unique()->values()->all(),
        );
    }

    public function test_repeat_publication_is_rejected(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $run = $this->scheduleRun($term);
        $this->candidate($run, $this->demand($term));

        $this->publisher->publish($run, $registrar);

        $this->assertPublicationRejected($run, $registrar, 'status');
        $this->assertSame(1, SectionMeeting::query()->count());
        $this->assertSame(1, $run->fresh()->publication_version);
    }

    public function test_transaction_failure_rolls_back_all_official_meetings_and_run_metadata(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $run = $this->scheduleRun($term);
        $demand = $this->demand($term, meetingCount: 2);
        $this->candidate($run, $demand, meetingSequence: 1);
        $this->candidate($run, $demand, meetingSequence: 2, startsAt: '11:00:00', endsAt: '12:00:00');
        $eventName = 'eloquent.created: '.SectionMeeting::class;

        Event::listen($eventName, function (SectionMeeting $meeting): void {
            if ($meeting->meeting_sequence === 2) {
                throw new RuntimeException('Forced second-row publication failure.');
            }
        });

        try {
            $this->publisher->publish($run, $registrar);
            $this->fail('Forced publication failure did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced second-row publication failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $run->refresh();

        $this->assertSame(0, SectionMeeting::query()->count());
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
        $this->assertNull($run->published_by);
        $this->assertNull($run->published_at);
        $this->assertNull($run->publication_version);
        $this->assertSame(0, DB::table('activity_log')->where('subject_type', ScheduleGenerationRun::class)->where('subject_id', $run->id)->count());
    }

    public function test_filament_action_visibility_and_read_only_official_schedule_surface_follow_authorization_and_state(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();
        $publishableRun = $this->scheduleRun($term);
        $this->candidate($publishableRun, $this->demand($term));
        $blockedRun = $this->scheduleRun($term, ScheduleGenerationRun::StatusBlocked);
        $this->candidate($blockedRun, $this->demand($term));

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $publishableRun->getRouteKey()])
            ->assertActionVisible('publishSchedule');

        Livewire::actingAs($academicHead)
            ->test(ViewScheduleGenerationRun::class, ['record' => $publishableRun->getRouteKey()])
            ->assertActionHidden('publishSchedule');

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $blockedRun->getRouteKey()])
            ->assertActionHidden('publishSchedule');

        $published = $this->publisher->publish($publishableRun, $registrar);
        $meeting = $published->sectionMeetings()->firstOrFail();

        $this->assertTrue(Route::has('filament.admin.resources.section-meetings.index'));
        $this->assertFalse(Route::has('filament.admin.resources.section-meetings.create'));
        $this->assertTrue(Gate::forUser($registrar)->allows('viewAny', SectionMeeting::class));
        $this->assertTrue(Gate::forUser($academicHead)->allows('viewAny', SectionMeeting::class));

        Livewire::actingAs($registrar)
            ->test(ListSectionMeetings::class)
            ->assertCanSeeTableRecords([$meeting])
            ->assertActionDoesNotExist('create');

        $this->actingAs($registrar)
            ->get(SectionMeetingResource::getUrl())
            ->assertOk();
    }

    private function assertPublicationRejected(ScheduleGenerationRun $run, User $publisher, string $errorKey): void
    {
        try {
            $this->publisher->publish($run, $publisher);
            $this->fail('Invalid publication was not rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function scheduleRun(
        Term $term,
        string $status = ScheduleGenerationRun::StatusUnderReview,
        array $overrides = [],
    ): ScheduleGenerationRun {
        return ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $status,
            'requested_by' => null,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal66-test-solver',
            ...$overrides,
        ]);
    }

    private function demand(
        Term $term,
        string $modality = TermOffering::ModalityFaceToFace,
        int $meetingCount = 1,
    ): SchedulingDemand {
        $offering = TermOffering::factory()->for($term)->create(['modality' => $modality]);
        $component = CourseComponent::factory()->create();
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $group = SectionDeliveryGroup::factory()->for($section)->create(['modality' => $modality]);

        return SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'modality' => $modality,
                'meeting_count' => $meetingCount,
            ]);
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     * @param  list<array<string, mixed>>  $violations
     */
    private function candidate(
        ScheduleGenerationRun $run,
        SchedulingDemand $demand,
        string $status = CandidateScheduleRow::StatusOk,
        int|false|null $roomId = false,
        array $warnings = [],
        array $violations = [],
        int $meetingSequence = 1,
        string $startsAt = '08:00:00',
        string $endsAt = '10:00:00',
    ): CandidateScheduleRow {
        return CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => $meetingSequence,
            'faculty_user_id' => $this->faculty->id,
            'room_id' => $roomId === false ? $this->room->id : $roomId,
            'day_of_week' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'time_block_key' => 'D1-'.str_replace(':', '', mb_substr($startsAt, 0, 5)),
            'status' => $status,
            'scores' => [],
            'warnings' => $warnings,
            'violations' => $violations,
        ]);
    }

    private function officialMeeting(
        ScheduleGenerationRun $run,
        SchedulingDemand $demand,
        Carbon $publishedAt,
    ): SectionMeeting {
        return SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $this->faculty->id,
            'room_id' => $this->room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => $demand->modality,
            'state' => SectionMeeting::StateActive,
            'published_at' => $publishedAt,
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
