<?php

namespace Tests\Feature;

use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Filament\Resources\ScheduleGenerationRuns\RelationManagers\RevisionEventsRelationManager;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\TimetableRevision;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94D3aRevisionUxTest extends TestCase
{
    use DatabaseTransactions;

    private int $contextCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), [
            'tala_db',
            'demo_tala_db',
            'tala_test_codex',
        ]);

        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_revision_action_and_history_follow_role_and_published_state_boundaries(): void
    {
        $published = $this->context();
        $draft = $this->context(runStatus: ScheduleGenerationRun::StatusUnderReview);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $published['run']->getRouteKey()])
            ->assertOk()
            ->assertSee('Open issued timetable')
            ->assertSee('Term revision history')
            ->assertDontSee('role="tablist"', escape: false)
            ->assertDontSee('Review accepted. Publish after')
            ->assertActionVisible('revisePublishedSchedule')
            ->assertActionVisible('cancelPublishedClass');

        Livewire::actingAs($academicHead)
            ->test(ViewScheduleGenerationRun::class, ['record' => $published['run']->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('revisePublishedSchedule')
            ->assertActionHidden('cancelPublishedClass');

        Livewire::actingAs($academicHead)
            ->test(RevisionEventsRelationManager::class, [
                'ownerRecord' => $published['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->assertOk();

        Livewire::actingAs($superAdmin)
            ->test(RevisionEventsRelationManager::class, [
                'ownerRecord' => $published['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->assertForbidden();

        Livewire::actingAs($superAdmin)
            ->test(ViewScheduleGenerationRun::class, ['record' => $published['run']->getRouteKey()])
            ->assertForbidden();

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $draft['run']->getRouteKey()])
            ->assertActionHidden('revisePublishedSchedule')
            ->assertActionHidden('cancelPublishedClass');

        foreach ([
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
            'student',
            'applicant',
        ] as $deniedRole) {
            $user = $this->staff($deniedRole);

            $this->assertFalse(Gate::forUser($user)->allows('view', $published['run']));
            $this->assertFalse(Gate::forUser($user)->allows('revise', SectionMeeting::class));
        }
    }

    public function test_meeting_context_prefills_the_target_and_cancel_does_not_publish(): void
    {
        $context = $this->context(meetingCount: 2);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $meeting = $context['meetings'][0];

        $component = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->mountAction('revisePublishedSchedule', ['meeting' => $meeting->id])
            ->assertActionDataSet(['section_meeting_ids' => [$meeting->id]])
            ->assertMountedActionModalSee('What do you need to change?')
            ->assertMountedActionModalDontSee('Check the impact')
            ->setActionData([
                'change_type' => ScheduleRevisionEvent::ChangeRoom,
                'section_meeting_ids' => [$meeting->id],
                'replacement_room_id' => $context['replacementRoom']->id,
            ])
            ->assertMountedActionModalSee('Current')
            ->assertMountedActionModalSee('Proposed')
            ->assertMountedActionModalSee('Check the impact');

        $component->unmountAction()->assertActionNotMounted();
        $this->assertDatabaseCount('schedule_revision_events', 0);
        $this->assertSame($context['room']->id, $meeting->fresh()->room_id);

        $component->mountAction('cancelPublishedClass', ['meeting' => $meeting->id])
            ->assertActionDataSet([
                'section_id' => $context['section']->id,
                'change_type' => ScheduleRevisionEvent::ChangeSectionCancellation,
            ])
            ->assertMountedActionModalSee('whole class')
            ->assertMountedActionModalSee('Cancelled')
            ->unmountAction();

        $this->assertSame(Section::StateOpen, $context['section']->fresh()->state);
        $this->assertDatabaseCount('schedule_revision_events', 0);
    }

    public function test_meeting_context_rejects_a_target_from_another_run(): void
    {
        $context = $this->context();
        $other = $this->context();

        Livewire::actingAs($this->staff(User::StaffRoleRegistrar))
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->mountAction('revisePublishedSchedule', ['meeting' => $other['meetings'][0]->id])
            ->assertNotFound();

        $this->assertDatabaseCount('schedule_revision_events', 0);
    }

    public function test_timetable_defaults_to_week_and_preserves_explicit_list_links(): void
    {
        $term = Term::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);

        foreach ([
            [[], 'week'],
            [['timetableFormat' => 'list'], 'list'],
            [['timetableView' => 'list'], 'list'],
        ] as [$query, $expectedFormat]) {
            Livewire::actingAs($registrar)->withQueryParams(['termId' => $term->id, ...$query])
                ->test(TermTimetable::class)
                ->assertSet('timetableFormat', $expectedFormat)
                ->assertSet('timetableView', 'whole');
        }
    }

    public function test_week_meeting_details_resolve_beyond_the_list_page_without_exposing_other_records(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $version = PublishedTimetableVersion::factory()->create([
            'term_id' => $context['term']->id,
            'schedule_run_id' => $context['run']->id,
            'published_by' => $registrar->id,
        ]);

        for ($index = 0; $index < 12; $index++) {
            $issued = PublishedTimetableMeeting::factory()->create([
                'published_timetable_version_id' => $version->id,
                'section_id' => $context['section']->id,
                'scheduling_demand_id' => $context['demand']->id,
                'faculty_user_id' => $context['faculty']->id,
                'room_id' => $context['room']->id,
                'meeting_sequence' => $index + 1,
                'day_of_week' => intdiv($index, 3) + 1,
                'starts_at' => ['08:00:00', '10:00:00', '14:00:00'][$index % 3],
                'ends_at' => ['09:00:00', '11:00:00', '15:00:00'][$index % 3],
            ]);
        }

        $component = Livewire::actingAs($registrar)->withQueryParams([
            'termId' => $context['term']->id,
            'timetableState' => 'official',
            'selectedVersionId' => $version->id,
            'timetableFormat' => 'week',
        ])->test(TermTimetable::class);

        $this->assertCount(10, $component->instance()->getTableRecords());
        DB::enableQueryLog();

        try {
            $component->mountTableAction('viewMeeting', (string) $issued->id)
                ->assertMountedActionModalSee('Thursday')
                ->assertMountedActionModalSee('2:00 PM–3:00 PM');

            $planningLoads = collect(DB::getQueryLog())->filter(
                fn (array $query): bool => str_contains($query['query'], 'from `terms` order by `starts_on` desc'),
            )->count();
            $unselectedMeetings = collect(DB::getQueryLog())->filter(
                fn (array $query): bool => str_contains($query['query'], 'from `candidate_schedule_rows`'),
            )->count();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(0, $planningLoads, 'Opening a meeting must not load the whole preparation workspace.');
        $this->assertSame(0, $unselectedMeetings, 'Opening an official meeting must not load candidate assignments.');

        $component->unmountAction()
            ->mountTableAction('viewMeeting', 'not-in-this-timetable')
            ->assertActionNotMounted();

        $context['room']->update(['code' => 'UPDATED-MEETING-ROOM']);

        $component->mountTableAction('viewMeeting', (string) $issued->id)
            ->assertMountedActionModalSee('UPDATED-MEETING-ROOM')
            ->unmountAction()
            ->set('tableSearch', 'not-a-visible-meeting')
            ->mountTableAction('viewMeeting', (string) $issued->id)
            ->assertActionNotMounted();
    }

    public function test_candidate_meeting_inspection_does_not_load_the_issued_meetings(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        PublishedTimetableVersion::factory()->create([
            'term_id' => $context['term']->id,
            'schedule_run_id' => $context['run']->id,
            'published_by' => $registrar->id,
        ]);
        $candidate = ScheduleGenerationRun::factory()->create([
            'term_id' => $context['term']->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'UnderReview',
            'published_at' => null,
            'published_by' => null,
            'publication_version' => null,
            'input_snapshot' => $context['run']->input_snapshot,
        ]);
        $meeting = $candidate->candidateRows()->create([
            'scheduling_demand_id' => $context['demand']->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $context['faculty']->id,
            'room_id' => $context['room']->id,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '11:00:00',
            'time_block_key' => 'D2-1000',
            'status' => 'ok',
        ]);
        $component = Livewire::actingAs($registrar)->withQueryParams([
            'termId' => $context['term']->id,
            'selectedRunId' => $candidate->id,
            'timetableFormat' => 'week',
        ])->test(TermTimetable::class);

        DB::enableQueryLog();

        try {
            $component->mountTableAction('viewMeeting', (string) $meeting->id)
                ->assertMountedActionModalSee('Tuesday')
                ->assertMountedActionModalSee('10:00 AM–11:00 AM')
                ->assertMountedActionModalSee('Review or change assignments')
                ->assertMountedActionModalDontSee('Change this meeting');

            $issuedMeetingLoads = collect(DB::getQueryLog())->filter(
                fn (array $query): bool => str_contains($query['query'], 'from `published_timetable_meetings`'),
            )->count();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(0, $issuedMeetingLoads, 'Inspecting a candidate must not load the issued meetings.');
    }

    public function test_official_timetable_never_inherits_a_later_failed_attempt_warning(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $version = PublishedTimetableVersion::factory()->create([
            'term_id' => $context['term']->id, 'schedule_run_id' => $context['run']->id,
            'published_by' => $registrar->id,
        ]);
        $failed = ScheduleGenerationRun::factory()->create([
            'term_id' => $context['term']->id, 'status' => ScheduleGenerationRun::StatusFailed,
            'published_at' => null, 'candidate_state' => null,
            'diagnostics' => ['solver_dispatch' => ['status' => 'failed', 'failure' => [
                'code' => 'LOCAL_REVIEW_FAILURE', 'message' => 'The later attempt failed locally.',
                'retryable' => true, 'final' => true,
            ]]],
        ]);
        $official = Livewire::actingAs($registrar)->withQueryParams([
            'termId' => $context['term']->id, 'selectedVersionId' => $version->id,
        ])->test(TermTimetable::class)->assertDontSee('The later attempt failed locally.');
        $this->assertSame([], $official->viewData('solverFailure'));
        Livewire::withQueryParams([
            'termId' => $context['term']->id, 'selectedRunId' => $failed->id,
        ])->test(TermTimetable::class)->assertSee('The later attempt failed locally.');
    }

    public function test_review_return_preserves_the_exact_issued_version_and_view_context(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $original = PublishedTimetableVersion::factory()->create([
            'term_id' => $context['term']->id, 'schedule_run_id' => $context['run']->id,
            'published_by' => $registrar->id, 'version' => 1,
            'state' => PublishedTimetableVersion::StateSuperseded,
        ]);
        PublishedTimetableVersion::factory()->create([
            'term_id' => $context['term']->id, 'schedule_run_id' => $context['run']->id,
            'published_by' => $registrar->id, 'version' => 2,
        ]);
        $criteria = [
            'selectedVersionId' => $original->id, 'timetableView' => 'room',
            'timetableFormat' => 'list', 'viewFilter' => $context['room']->code,
            'tableSearch' => 'review room', 'programFilter' => 'Exact program', 'yearFilter' => 'First Year',
        ];
        $review = Livewire::actingAs($registrar)->withQueryParams([
            'termId' => $context['term']->id, 'timetableContext' => $criteria,
        ])->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->id]);
        parse_str(parse_url($review->instance()->termPlanningReturnUrl(), PHP_URL_QUERY), $returned);
        foreach ($criteria as $key => $value) {
            $this->assertSame((string) $value, (string) $returned[$key]);
        }
        $this->assertSame((string) $context['term']->id, $returned['termId']);

        $review->set('timetableContext', ['selectedVersionId' => 999999999, 'timetableFormat' => 'unsafe']);
        parse_str(parse_url($review->instance()->termPlanningReturnUrl(), PHP_URL_QUERY), $invalid);
        $this->assertArrayNotHasKey('timetableFormat', $invalid);
        $this->assertNotSame('999999999', $invalid['selectedVersionId']);
    }

    public function test_issued_meeting_details_offer_contextual_changes_only_to_the_registrar(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $meeting = $context['meetings'][0];
        $version = PublishedTimetableVersion::factory()->create([
            'term_id' => $context['term']->id,
            'schedule_run_id' => $context['run']->id,
            'published_by' => $registrar->id,
        ]);
        $issued = PublishedTimetableMeeting::factory()->create([
            'published_timetable_version_id' => $version->id,
            'section_id' => $context['section']->id,
            'scheduling_demand_id' => $context['demand']->id,
            'faculty_user_id' => $context['faculty']->id,
            'room_id' => $context['room']->id,
            'meeting_sequence' => $meeting->meeting_sequence,
            'day_of_week' => $meeting->day_of_week,
            'starts_at' => $meeting->starts_at,
            'ends_at' => $meeting->ends_at,
        ]);
        $parameters = [
            'termId' => $context['term']->id,
            'timetableState' => 'official',
            'selectedVersionId' => $version->id,
        ];

        Livewire::actingAs($registrar)->withQueryParams($parameters)->test(TermTimetable::class)
            ->mountTableAction('viewMeeting', (string) $issued->id)
            ->assertMountedActionModalSee('Change this meeting')
            ->assertMountedActionModalSee('Cancel this class')
            ->assertMountedActionModalSee('actionArguments');

        Livewire::actingAs($this->staff(User::StaffRoleAcademicHead))
            ->withQueryParams($parameters)->test(TermTimetable::class)
            ->mountTableAction('viewMeeting', (string) $issued->id)
            ->assertMountedActionModalDontSee('Change this meeting')
            ->assertMountedActionModalDontSee('Cancel this class');

        $version->update(['state' => PublishedTimetableVersion::StateSuperseded]);
        Livewire::actingAs($registrar)->withQueryParams($parameters)->test(TermTimetable::class)
            ->mountTableAction('viewMeeting', (string) $issued->id)
            ->assertMountedActionModalDontSee('Change this meeting')
            ->assertMountedActionModalDontSee('Cancel this class');
    }

    public function test_registrar_sees_a_structured_preview_and_applies_a_room_revision(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $meeting = $context['meetings'][0];

        $component = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->mountAction('revisePublishedSchedule')
            ->setActionData([
                'change_type' => ScheduleRevisionEvent::ChangeRoom,
                'section_meeting_ids' => [$meeting->id],
                'replacement_room_id' => $context['replacementRoom']->id,
                'authority_reference' => null,
                'reason' => 'The original room is unavailable.',
            ])
            ->assertMountedActionModalSee('Effective immediately when published')
            ->assertMountedActionModalSee('Meeting checks passed')
            ->assertMountedActionModalSee($context['room']->code)
            ->assertMountedActionModalSee($context['replacementRoom']->code)
            ->assertMountedActionModalDontSee('old_snapshot_json');

        $component
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Published schedule revised')
            ->assertRedirect(TermTimetable::getUrl(['termId' => $context['run']->term_id, 'timetableState' => 'official']));

        $event = ScheduleRevisionEvent::query()->sole();
        $successorMeeting = SectionMeeting::query()->findOrFail($event->section_meeting_id);

        $this->assertSame($context['room']->id, $meeting->fresh()->room_id);
        $this->assertSame($context['replacementRoom']->id, $successorMeeting->room_id);
        $this->assertSame(ScheduleRevisionEvent::ChangeRoom, $event->change_type);
        $this->assertSame(now()->toDateString(), $event->effective_date->toDateString());
        $this->assertSame($registrar->id, $event->changed_by);
        $revision = TimetableRevision::query()->sole();
        $this->assertNull($revision->authority_reference);
        $this->assertSame($registrar->id, $revision->published_by);
        $this->assertNotNull($revision->published_at);
        $this->assertNull(PublishedTimetableVersion::query()->findOrFail($revision->successor_version_id)->authority_reference);
    }

    public function test_optional_revision_reference_preserves_supplied_evidence_and_requires_a_reason(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $data = [
            'change_type' => ScheduleRevisionEvent::ChangeRoom,
            'section_meeting_ids' => [$context['meetings'][0]->id],
            'replacement_room_id' => $context['replacementRoom']->id,
            'authority_reference' => '  SYNTH-APPROVED-ROOM-CHANGE  ',
            'reason' => '',
        ];

        $component = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->mountAction('revisePublishedSchedule')
            ->setActionData($data)
            ->callMountedAction()
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertDatabaseCount('schedule_revision_events', 0);
        $this->assertSame($context['room']->id, $context['meetings'][0]->fresh()->room_id);

        $data['reason'] = 'The original room is unavailable.';
        $component->setActionData($data)->callMountedAction()->assertHasNoActionErrors();

        $revision = TimetableRevision::query()->sole();
        $this->assertSame('SYNTH-APPROVED-ROOM-CHANGE', $revision->authority_reference);
        $this->assertSame('SYNTH-APPROVED-ROOM-CHANGE', PublishedTimetableVersion::query()->findOrFail($revision->successor_version_id)->authority_reference);
    }

    public function test_registrar_can_apply_faculty_time_modality_and_whole_section_revision_paths(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $facultyContext = $this->context();
        $replacementFaculty = $this->staff(User::StaffRoleFaculty);
        FacultyQualification::factory()
            ->for($replacementFaculty, 'faculty')
            ->for($facultyContext['course'])
            ->create(['is_active' => true]);

        $this->callRevision($registrar, $facultyContext['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeFacultyReassignment,
            'section_meeting_ids' => [$facultyContext['meetings'][0]->id],
            'replacement_faculty_user_id' => $replacementFaculty->id,
            'reason' => 'Faculty reassignment approved.',
        ]);
        $this->assertSame(
            $replacementFaculty->id,
            $this->currentMeeting($facultyContext['term'], $facultyContext['demand'])->faculty_user_id,
        );

        $timeContext = $this->context();
        $this->callRevision($registrar, $timeContext['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeTime,
            'section_meeting_ids' => [$timeContext['meetings'][0]->id],
            'day_of_week' => 2,
            'starts_at' => '09:00:00',
            'ends_at' => '12:00:00',
            'reason' => 'Time change approved.',
        ]);
        $timeMeeting = $this->currentMeeting($timeContext['term'], $timeContext['demand']);
        $this->assertSame(2, $timeMeeting->day_of_week);
        $this->assertSame('09:00:00', $timeMeeting->starts_at);

        $modalityContext = $this->context();
        $modalityContext['meetings'][0]->forceFill([
            'modality' => TermOffering::ModalityOnline,
            'room_id' => null,
        ])->save();
        $this->callRevision($registrar, $modalityContext['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeDeliveryModality,
            'section_meeting_ids' => [$modalityContext['meetings'][0]->id],
            'replacement_room_id' => $modalityContext['room']->id,
            'reason' => 'Delivery modality corrected to the authoritative source.',
        ]);
        $modalityMeeting = $this->currentMeeting($modalityContext['term'], $modalityContext['demand']);
        $this->assertSame(TermOffering::ModalityFaceToFace, $modalityMeeting->modality);
        $this->assertSame($modalityContext['room']->id, $modalityMeeting->room_id);

        $cancellationContext = $this->context(meetingCount: 2);
        $this->callRevision($registrar, $cancellationContext['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeSectionCancellation,
            'section_id' => $cancellationContext['section']->id,
            'reason' => 'The section is no longer required.',
        ]);
        $this->assertSame(Section::StateCancelled, $cancellationContext['section']->fresh()->state);
        $this->assertSame(SectionDeliveryGroup::StateCancelled, $cancellationContext['group']->fresh()->state);
        $this->assertSame(0, SectionMeeting::query()
            ->where('scheduling_demand_id', $cancellationContext['demand']->id)
            ->whereHas('scheduleRun', fn ($query) => $query
                ->where('term_id', $cancellationContext['term']->id)
                ->where('status', ScheduleGenerationRun::StatusPublished))
            ->count());
    }

    public function test_source_drift_after_preview_blocks_every_grouped_write(): void
    {
        $context = $this->context(meetingCount: 2);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $meetingIds = collect($context['meetings'])->pluck('id')->all();

        $component = Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $context['run']->getRouteKey()])
            ->mountAction('revisePublishedSchedule')
            ->setActionData([
                'change_type' => ScheduleRevisionEvent::ChangeRoom,
                'section_meeting_ids' => $meetingIds,
                'replacement_room_id' => $context['replacementRoom']->id,
                'authority_reference' => 'SYNTH-REVISION-SIGNOFF-STALE',
                'reason' => 'This grouped preview will become stale.',
            ])
            ->assertMountedActionModalSee('Meeting checks passed');

        $context['replacementRoom']->forceFill(['capacity' => 10])->save();

        $component
            ->callMountedAction()
            ->assertNotified('Published schedule revision blocked')
            ->assertHasActionErrors();

        $this->assertSame(count($meetingIds), SectionMeeting::query()
            ->whereIn('id', $meetingIds)
            ->where('room_id', $context['room']->id)
            ->count());
        $this->assertSame(0, ScheduleRevisionEvent::query()
            ->whereIn('section_meeting_id', $meetingIds)
            ->count());
    }

    public function test_revision_history_is_newest_first_filterable_read_only_and_uses_snapshot_fallbacks(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $meeting = $context['meetings'][0];

        $this->callRevision($registrar, $context['run'], [
            'change_type' => ScheduleRevisionEvent::ChangeRoom,
            'section_meeting_ids' => [$meeting->id],
            'replacement_room_id' => $context['replacementRoom']->id,
            'reason' => 'Room changed after facilities review.',
        ]);
        $first = ScheduleRevisionEvent::query()->sole();

        $this->travel(1)->minute();
        $currentRun = ScheduleGenerationRun::query()
            ->where('term_id', $context['term']->id)
            ->where('status', ScheduleGenerationRun::StatusPublished)
            ->sole();
        $currentMeeting = $this->currentMeeting($context['term'], $context['demand']);
        $this->callRevision($registrar, $currentRun, [
            'change_type' => ScheduleRevisionEvent::ChangeTime,
            'section_meeting_ids' => [$currentMeeting->id],
            'day_of_week' => 2,
            'starts_at' => '09:00:00',
            'ends_at' => '12:00:00',
            'reason' => 'Time changed after Registrar review.',
        ]);
        $second = ScheduleRevisionEvent::query()
            ->whereKeyNot($first->id)
            ->sole();

        $this->assertNotSame((int) $context['run']->id, (int) $second->sectionMeeting->schedule_run_id);

        Livewire::actingAs($academicHead)
            ->test(RevisionEventsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->assertSee($context['demand']->courseComponent->courseSpecification->title)
            ->assertSee($context['section']->code)
            ->assertTableColumnExists('change_type')
            ->assertTableColumnExists('effective_date')
            ->assertTableColumnExists('reason')
            ->assertTableColumnExists('affected_student_count')
            ->assertTableFilterExists('change_type')
            ->filterTable('change_type', ScheduleRevisionEvent::ChangeRoom)
            ->assertCanSeeTableRecords([$first])
            ->assertCanNotSeeTableRecords([$second])
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete')
            ->mountTableAction('view', $first)
            ->assertMountedActionModalSee('Before')
            ->assertMountedActionModalSee('After')
            ->assertMountedActionModalSee($context['room']->code)
            ->assertMountedActionModalDontSee('old_snapshot_json')
            ->assertMountedActionModalDontSee('new_snapshot_json');
    }

    public function test_revision_history_empty_state_is_limited_to_official_meeting_changes(): void
    {
        $context = $this->context();
        $academicHead = $this->staff(User::StaffRoleAcademicHead);

        Livewire::actingAs($academicHead)
            ->test(RevisionEventsRelationManager::class, [
                'ownerRecord' => $context['run'],
                'pageClass' => ViewScheduleGenerationRun::class,
            ])
            ->assertSee('No official meeting changes recorded')
            ->assertSee('normal until a published timetable meeting is revised')
            ->assertSee('Candidate edits are not recorded in Term revision history');
    }

    /** @param array<string, mixed> $data */
    private function callRevision(User $registrar, ScheduleGenerationRun $run, array $data): void
    {
        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $run->getRouteKey()])
            ->callAction(($data['change_type'] ?? null) === ScheduleRevisionEvent::ChangeSectionCancellation
                ? 'cancelPublishedClass' : 'revisePublishedSchedule', data: $data)
            ->assertHasNoActionErrors()
            ->assertNotified('Published schedule revised');
    }

    private function currentMeeting(Term $term, SchedulingDemand $demand): SectionMeeting
    {
        return SectionMeeting::query()
            ->where('scheduling_demand_id', $demand->id)
            ->whereHas('scheduleRun', fn ($query) => $query
                ->where('term_id', $term->id)
                ->where('status', ScheduleGenerationRun::StatusPublished))
            ->sole();
    }

    /** @return array<string, mixed> */
    private function context(
        int $meetingCount = 1,
        string $runStatus = ScheduleGenerationRun::StatusPublished,
    ): array {
        $this->contextCounter++;
        $term = Term::factory()->create([
            'label' => 'TAL-94D3A Term '.$this->contextCounter,
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
        ]);
        foreach ([1, 2, 3, 4, 5, 6] as $day) {
            $package->teachingGridRows()->create([
                'day_of_week' => $day,
                'starts_at' => '07:00:00',
                'ends_at' => '21:00:00',
                'breaks' => [],
            ]);
        }
        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create([
            'code' => 'D3A-R'.$this->contextCounter.'A',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $replacementRoom = Room::factory()->create([
            'code' => 'D3A-R'.$this->contextCounter.'B',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $program = Program::factory()->create(['code' => 'D3A'.$this->contextCounter]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'D3AC'.$this->contextCounter]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [
                TermOffering::ModalityFaceToFace,
                TermOffering::ModalityOnline,
            ],
            'credit_units' => 3.00,
            'same_faculty_default' => true,
        ]);
        $component = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'meeting_pattern' => $meetingCount === 2 ? '2x90' : '1x180',
            'room_type_default' => Room::TypeLectureRoom,
            'required_room_feature_keys' => [],
            'requires_consecutive_block' => false,
            'sequence' => 1,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
            'term_type' => $term->type,
            'term_label' => $term->label,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
            'expected_count' => 30,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'D3A-S'.$this->contextCounter,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'TAL-94D3A Group '.$this->contextCounter,
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create(['is_active' => true]);
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'required_duration_minutes' => $meetingCount === 2 ? 90 : 180,
                'meeting_count' => $meetingCount,
                'modality' => TermOffering::ModalityFaceToFace,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $runStatus,
            'requested_by' => null,
            'input_snapshot' => [
                'scheduling_demands' => [['scheduling_demand_id' => $demand->id]],
            ],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal94d3a-test-solver',
            'published_at' => $runStatus === ScheduleGenerationRun::StatusPublished ? now()->subDay() : null,
            'publication_version' => $runStatus === ScheduleGenerationRun::StatusPublished ? 1 : null,
        ]);
        $meetings = [];

        foreach (range(1, $meetingCount) as $sequence) {
            $meetings[] = SectionMeeting::query()->create([
                'schedule_run_id' => $run->id,
                'scheduling_demand_id' => $demand->id,
                'meeting_sequence' => $sequence,
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => (($sequence - 1) * 2) + 1,
                'starts_at' => '08:00:00',
                'ends_at' => $meetingCount === 2 ? '09:30:00' : '11:00:00',
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionMeeting::StateActive,
                'published_at' => now()->subDay(),
            ]);
        }

        return compact(
            'term',
            'faculty',
            'room',
            'replacementRoom',
            'program',
            'course',
            'offering',
            'section',
            'group',
            'demand',
            'run',
            'meetings',
        );
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
