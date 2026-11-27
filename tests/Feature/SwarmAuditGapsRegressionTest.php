<?php

namespace Tests\Feature;

use App\Actions\AcademicSetup\CourseSpecificationLifecycleService;
use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Actions\AcademicSetup\CurriculumWorkbenchService;
use App\Actions\Admissions\ChangeAdmissionCycle;
use App\Actions\Admissions\PublishAdmissionRequirementSet;
use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Actions\Scheduling\ReviewTimetableCandidate;
use App\Actions\Scheduling\ScheduleCloudResultIngestor;
use App\Actions\Scheduling\ScheduleSolverDispatchLifecycleService;
use App\Filament\Components\LiveDatabaseNotifications;
use App\Filament\Pages\SchoolResources;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermOverview;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\CalendarEvent;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use App\Policies\AdmissionCyclePolicy;
use App\Policies\RoomPolicy;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SwarmAuditGapsRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cancelled_solver_run_cannot_be_overwritten_or_retried_and_transport_failure_notifies_requester(): void
    {
        $registrar = $this->registrar();
        $term = Term::factory()->create();

        $cancelledRun = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusFailed,
            'diagnostics' => [
                'solver_dispatch' => ['status' => 'cancelled'],
                'solver_failure' => [
                    'final' => true,
                    'error_code' => 'CANCELLED_BY_REGISTRAR',
                    'message' => 'Cancelled by Registrar.',
                ],
            ],
        ]);

        $this->assertFalse($cancelledRun->canRetrySolver());

        try {
            app(ScheduleCloudResultIngestor::class)->ingest($cancelledRun, [
                'status' => 'OPTIMAL',
                'assignments' => [],
            ]);
            $this->fail('Ingesting into a cancelled/failed run must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(ScheduleGenerationRun::StatusFailed, $cancelledRun->fresh()->status);
        }

        $queuedRun = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusDispatching,
        ]);

        $event = OperationalEvent::factory()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationSchedulingSolver,
            'event_type' => OperationalEvent::TypeSolverDispatchAttempt,
            'status' => OperationalEvent::StatusPending,
            'related_record_type' => ScheduleGenerationRun::class,
            'related_record_id' => $queuedRun->id,
            'diagnostics' => ['cycle' => 1, 'attempt' => 1],
        ]);
        $failure = SchedulingSolverTransportException::retryable(
            SchedulingSolverTransportException::ClassificationConnection,
            'Connection refused',
        );

        app(ScheduleSolverDispatchLifecycleService::class)->markFailed(
            $queuedRun,
            $event,
            $failure,
            durationMs: 100,
            final: true,
        );

        $this->assertSame(1, $registrar->notifications()->count());
        $this->assertStringContainsString('Timetable generation failed', (string) ($registrar->notifications()->first()->data['title'] ?? ''));
    }

    public function test_accepted_unpublished_timetable_draft_can_be_discarded(): void
    {
        $registrar = $this->registrar();
        $term = Term::factory()->create();
        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->for($program)->create();
        $spec = CourseSpecification::factory()->create();
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $group = SectionDeliveryGroup::factory()->for($section, 'section')->create();
        $demand = SchedulingDemand::factory()->for($offering, 'termOffering')->for($group, 'sectionDeliveryGroup')->create();
        $room = Room::factory()->create(['is_active' => true]);

        $acceptedDraft = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'Accepted',
            'published_at' => null,
        ]);

        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $acceptedDraft->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $registrar->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'time_block_key' => 'mon-0800-0930',
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        $rejected = app(ReviewTimetableCandidate::class)->reject($acceptedDraft, $registrar, 'Superseded before publish');
        $this->assertSame('Rejected', $rejected->candidate_state);
    }

    public function test_live_database_notifications_caps_replay_cursor_after_long_idle(): void
    {
        $user = User::factory()->create();
        $cursorKey = 'filament.live_database_notifications.cursor.default.'.$user->getAuthIdentifier();
        session()->put($cursorKey, now()->subHours(2)->toIso8601String());

        Notification::make()
            ->title('Old idle notification')
            ->info()
            ->sendToDatabase($user);

        $oldId = (string) $user->notifications()->latest('created_at')->value('id');
        $user->notifications()->where('id', $oldId)->update(['created_at' => now()->subMinutes(10)]);

        Livewire::actingAs($user)
            ->test(LiveDatabaseNotifications::class)
            ->call('$refresh')
            ->assertNotDispatched('notificationsSent');
    }

    public function test_closing_term_closes_active_and_draft_calendar_packages_while_older_drafts_do_not_override_active_ui(): void
    {
        $registrar = $this->registrar();
        $term = Term::factory()->create(['state' => Term::StateDraft]);

        $olderDraft = TermCalendarPackage::factory()->for($term)->create([
            'version' => 1,
            'state' => TermCalendarPackage::StateDraft,
        ]);

        $activeDraft = TermCalendarPackage::factory()->for($term)->create([
            'version' => 2,
            'state' => TermCalendarPackage::StateDraft,
            'classes_start_on' => $term->starts_on,
            'classes_end_on' => $term->ends_on,
            'administrative_starts_on' => $term->starts_on,
            'administrative_ends_on' => $term->ends_on,
        ]);
        $activeDraft->teachingGridRows()->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);

        app(ActivateTermCalendarPackage::class)->execute(
            $activeDraft->fresh(['teachingGridRows', 'windows', 'datedExceptions']),
            $registrar,
            $activeDraft->fresh()->concurrencyToken(),
        );

        // Activating v2 preserves v1 as Draft (immutable audit / explicit draft preservation).
        $this->assertSame(TermCalendarPackage::StateActive, $activeDraft->fresh()->state);
        $this->assertSame(TermCalendarPackage::StateDraft, $olderDraft->fresh()->state);

        // An older lingering Draft (v1 < v2) must NOT override the Active calendar UI.
        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id])
            ->assertSee('Active')
            ->assertSee('Edit calendar')
            ->assertDontSee('Review and activate this draft when ready');

        // Closing the term closes both Active and lingering Draft calendar packages.
        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id])
            ->callAction('closeTerm')
            ->assertHasNoActionErrors();

        $this->assertSame(Term::StateClosed, $term->fresh()->state);
        $this->assertSame(TermCalendarPackage::StateClosed, $activeDraft->fresh()->state);
        $this->assertSame(TermCalendarPackage::StateClosed, $olderDraft->fresh()->state);
    }

    public function test_cancelling_class_via_proposal_purges_unreferenced_scheduling_demands(): void
    {
        $registrar = $this->registrar();
        $term = Term::factory()->create();
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        $package->teachingGridRows()->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [],
        ]);

        $program = Program::factory()->create(['is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['state' => Course::StateActive]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $component = CourseComponent::factory()->for($spec, 'courseSpecification')->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3,
            'meeting_pattern' => '2x90',
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create([
            'term_type' => $term->type,
        ]);
        $cohort = TermCohort::factory()->for($term)->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
            'confirmed_count' => 25,
            'confirmed_at' => now(),
        ]);

        $proposal = app(PrepareTermClassProposal::class);
        $preview = $proposal->preview($term);
        $proposal->confirm($term, $registrar, [[
            'section_id' => null,
            'entry_id' => $entry->id,
            'code' => 'CANCEL-DEMAND-1A',
            'source' => Section::SourceRegular,
            'modality' => TermOffering::ModalityFaceToFace,
            'capacity' => 30,
            'cohorts' => [['id' => $cohort->id, 'count' => 25]],
            'authority_reference' => null,
            'action' => 'confirm',
        ]], $preview['token']);

        $section = Section::query()->where('code', 'CANCEL-DEMAND-1A')->firstOrFail();
        $group = $section->deliveryGroups()->firstOrFail();
        $this->assertDatabaseHas('scheduling_demands', [
            'section_delivery_group_id' => $group->id,
            'course_component_id' => $component->id,
        ]);

        $cancelPreview = $proposal->preview($term);
        $proposal->confirm($term, $registrar, [[
            ...$cancelPreview['classes'][0],
            'action' => 'cancel',
            'cancellation_reason' => 'Merged with another class.',
        ]], $cancelPreview['token']);

        $this->assertSame(Section::StateCancelled, $section->fresh()->state);
        $this->assertSame(SectionDeliveryGroup::StateCancelled, $group->fresh()->state);
        $this->assertDatabaseMissing('scheduling_demands', ['section_delivery_group_id' => $group->id]);

        // Running demand generation again must not resurrect demands for cancelled sections.
        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $this->assertDatabaseMissing('scheduling_demands', ['section_delivery_group_id' => $group->id]);
    }

    public function test_school_resources_only_allows_registrar_to_delete_unreferenced_rooms(): void
    {
        $registrar = $this->registrar();
        $term = Term::factory()->create();
        $policy = app(RoomPolicy::class);

        $unusedRoom = Room::factory()->create();
        $this->assertFalse($policy->delete($registrar, $unusedRoom));
        Livewire::actingAs($registrar)
            ->withQueryParams(['tab' => 'rooms'])
            ->test(SchoolResources::class)
            ->assertTableActionVisible('deleteRoom', $unusedRoom);

        CalendarEvent::query()->create([
            'term_id' => $term->id,
            'room_id' => $unusedRoom->id,
            'event_type' => CalendarEvent::TypeUnavailable,
            'scope_type' => CalendarEvent::ScopeRoom,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
            'authority' => 'Registrar Office',
        ]);

        $deletableRoom = Room::factory()->create();
        $academicHead = User::factory()->create();
        $academicHead->assignRole(Role::findOrCreate(User::StaffRoleAcademicHead, 'web'));

        Livewire::actingAs($academicHead)
            ->withQueryParams(['tab' => 'rooms'])
            ->test(SchoolResources::class)
            ->assertTableActionHidden('deleteRoom', $deletableRoom);

        Livewire::actingAs($registrar)
            ->withQueryParams(['tab' => 'rooms'])
            ->test(SchoolResources::class)
            ->assertTableActionHidden('deleteRoom', $unusedRoom)
            ->callTableAction('deleteRoom', $deletableRoom)
            ->assertHasNoActionErrors();

        $this->assertModelExists($unusedRoom);
        $this->assertModelMissing($deletableRoom);
    }

    public function test_unbatched_curriculum_activation_does_not_retire_active_batched_curricula_and_discard_cleans_orphan_draft_specs(): void
    {
        $registrar = $this->registrar();
        $program = Program::factory()->create(['is_active' => true]);
        $ay = AcademicYear::factory()->create(['state' => AcademicYear::StateActive]);

        $course = Course::factory()->create(['state' => Course::StateActive]);
        $activeSpec = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);

        $batchedActive = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        CurriculumVersionBatch::query()->create([
            'program_id' => $program->id,
            'curriculum_version_id' => $batchedActive->id,
            'academic_year_id' => $ay->id,
            'added_by' => $registrar->id,
        ]);

        $unbatchedDraft = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);
        CurriculumEntry::factory()->for($unbatchedDraft, 'curriculumVersion')->for($activeSpec, 'courseSpecification')->create();

        app(CurriculumVersionLifecycleService::class)->activate($registrar, $unbatchedDraft);

        $this->assertSame(CurriculumVersion::StateActive, $unbatchedDraft->fresh()->state);
        $this->assertSame(CurriculumVersion::StateActive, $batchedActive->fresh()->state);

        // Orphaned DRAFT- fork cleanup on discardDraft and removeEntry.
        $forkedSpec = CourseSpecification::factory()->for($course)->create([
            'revision_code' => 'DRAFT-ORPHAN-01',
            'state' => CourseSpecification::StateDraft,
        ]);
        $draftToDiscard = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);
        CurriculumEntry::factory()->for($draftToDiscard, 'curriculumVersion')->for($forkedSpec, 'courseSpecification')->create();

        app(CurriculumVersionLifecycleService::class)->discardDraft($draftToDiscard, $registrar);
        $this->assertDatabaseMissing('course_specifications', ['id' => $forkedSpec->id]);
        $this->assertDatabaseHas('course_specifications', ['id' => $activeSpec->id]);

        $forkedSpec2 = CourseSpecification::factory()->for($course)->create([
            'revision_code' => 'DRAFT-ORPHAN-02',
            'state' => CourseSpecification::StateDraft,
        ]);
        $draftForRemove = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateDraft]);
        $entryToRemove = CurriculumEntry::factory()->for($draftForRemove, 'curriculumVersion')->for($forkedSpec2, 'courseSpecification')->create();

        app(CurriculumWorkbenchService::class)->removeEntry($registrar, $entryToRemove);
        $this->assertDatabaseMissing('course_specifications', ['id' => $forkedSpec2->id]);
    }

    public function test_course_specification_copy_to_draft_blocks_duplicate_drafts_for_same_course(): void
    {
        $registrar = $this->registrar();
        $course = Course::factory()->create(['state' => Course::StateActive]);
        $activeSpec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);

        $service = app(CourseSpecificationLifecycleService::class);
        $firstDraft = $service->copyToDraft($registrar, $activeSpec, 'DRAFT-REV-1');
        $this->assertSame(CourseSpecification::StateDraft, $firstDraft->state);

        $this->expectException(ValidationException::class);
        $service->copyToDraft($registrar, $activeSpec, 'DRAFT-REV-2');
    }

    public function test_draft_admission_cycle_remains_discardable_after_deadlines_and_publish_cleans_stale_draft_requirement_sets(): void
    {
        $registrar = $this->registrar();
        $cycle = AdmissionCycle::factory()->create([
            'state' => AdmissionCycle::StateDraft,
            'opens_at' => now()->addDay(),
            'closes_at' => now()->addDays(5),
            'correction_closes_at' => now()->addDays(7),
        ]);

        app(ChangeAdmissionCycle::class)->setDeadlines(
            $cycle,
            $registrar,
            now()->addDays(10),
            now()->addDays(15),
            now()->addDays(20),
            'Initial draft schedule',
            'MEMO-2026-01',
        );

        $draftSet1 = AdmissionRequirementSet::query()->create([
            'admission_cycle_id' => $cycle->id,
            'application_path' => AdmissionCycle::PathFirstYear,
            'version' => 1,
            'state' => AdmissionRequirementSet::StateDraft,
            'effective_at' => now()->addDay(),
        ]);
        $draftSet1->requirements()->create([
            'code' => 'F138',
            'label' => 'Form 138',
            'authority_reference' => 'CHED Memo',
            'purpose' => 'Verify graduation',
            'credential_classification' => AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential,
            'requires_preliminary_evidence' => true,
            'official_submission_method' => AdmissionRequirement::SubmissionInPerson,
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
            'applicant_instructions' => 'Upload copy',
            'registrar_instructions' => 'Verify seal',
            'exception_permitted' => false,
            'display_order' => 1,
        ]);

        // Draft cycle with events and draft requirement sets is still discardable until published or an application exists.
        $policy = app(AdmissionCyclePolicy::class);
        $this->assertTrue($policy->delete($registrar, $cycle->fresh()));

        // Publishing a newer draft requirement set cleans up older draft requirement sets for the same cycle & path.
        $draftSet2 = AdmissionRequirementSet::query()->create([
            'admission_cycle_id' => $cycle->id,
            'application_path' => AdmissionCycle::PathFirstYear,
            'version' => 2,
            'state' => AdmissionRequirementSet::StateDraft,
            'effective_at' => now()->addDay(),
        ]);
        $draftSet2->requirements()->create([
            'code' => 'F138',
            'label' => 'Form 138 Updated',
            'authority_reference' => 'CHED Memo',
            'purpose' => 'Verify graduation',
            'credential_classification' => AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential,
            'requires_preliminary_evidence' => true,
            'official_submission_method' => AdmissionRequirement::SubmissionInPerson,
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
            'applicant_instructions' => 'Upload copy',
            'registrar_instructions' => 'Verify seal',
            'exception_permitted' => false,
            'display_order' => 1,
        ]);

        app(PublishAdmissionRequirementSet::class)->execute($draftSet2, $registrar, 'MEMO-2026-02');
        $this->assertDatabaseMissing('admission_requirement_sets', ['id' => $draftSet1->id]);
        $this->assertSame(AdmissionRequirementSet::StatePublished, $draftSet2->fresh()->state);

        // Once a requirement set is published, the cycle can no longer be discarded.
        $this->assertFalse($policy->delete($registrar, $cycle->fresh()));

        // A separate draft cycle with only draft events + draft requirement sets CAN be discarded cleanly.
        $discardable = AdmissionCycle::factory()->create([
            'state' => AdmissionCycle::StateDraft,
            'opens_at' => now()->addDay(),
            'closes_at' => now()->addDays(5),
            'correction_closes_at' => now()->addDays(7),
        ]);
        app(ChangeAdmissionCycle::class)->setDeadlines(
            $discardable,
            $registrar,
            now()->addDays(10),
            now()->addDays(15),
            now()->addDays(20),
            'Draft test',
            'MEMO-2026-03',
        );
        AdmissionRequirementSet::query()->create([
            'admission_cycle_id' => $discardable->id,
            'application_path' => AdmissionCycle::PathFirstYear,
            'version' => 1,
            'state' => AdmissionRequirementSet::StateDraft,
            'effective_at' => now()->addDay(),
        ]);

        app(ChangeAdmissionCycle::class)->discardDraft($discardable, $registrar);
        $this->assertDatabaseMissing('admission_cycles', ['id' => $discardable->id]);

        // An application still blocks discard.
        $withApp = AdmissionCycle::factory()->create(['state' => AdmissionCycle::StateDraft]);
        AdmissionApplication::factory()->create(['admission_cycle_id' => $withApp->id, 'term_id' => $withApp->term_id]);
        $this->assertFalse($policy->delete($registrar, $withApp->fresh()));
    }

    public function test_school_resources_edit_room_saves_and_guards_official_meetings_without_schema_type_error(): void
    {
        $registrar = $this->registrar();
        $room = Room::factory()->create([
            'code' => 'CHEM01',
            'name' => 'Chemistry',
            'room_type' => Room::TypeLaboratory,
            'capacity' => 35,
            'is_active' => true,
        ]);

        // 1. Valid save through SchoolResources::editRoom must succeed without throwing a Schema TypeError.
        Livewire::actingAs($registrar)
            ->withQueryParams(['tab' => 'rooms'])
            ->test(SchoolResources::class)
            ->callTableAction('editRoom', $room, data: [
                'code' => 'CHEM01',
                'name' => 'Chemistry Lab Updated',
                'building' => 'Science Wing',
                'room_type' => Room::TypeLaboratory,
                'capacity' => 40,
                'is_active' => true,
                'features' => [
                    ['feature_key' => 'AIR_CONDITIONED'],
                ],
                'notes' => 'Updated via modal',
            ])
            ->assertHasNoTableActionErrors();

        $room->refresh();
        $this->assertSame('Chemistry Lab Updated', $room->name);
        $this->assertSame(40, (int) $room->capacity);
        $this->assertTrue($room->features()->where('feature_key', 'AIR_CONDITIONED')->exists());

        // 2. When assigned to an active official meeting, guardRoomEdit receives the mounted Schema and blocks unsafe edits.
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'CHEM101']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        $component = CourseComponent::factory()->for($spec, 'courseSpecification')->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'room_type_default' => Room::TypeLaboratory,
            'required_room_feature_keys' => ['AIR_CONDITIONED'],
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create(['term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $package->id,
            'capacity' => 35,
            'state' => Section::StateOpen,
        ]);
        $deliveryGroup = SectionDeliveryGroup::factory()->for($section)->create(['expected_count' => 35]);
        $demand = SchedulingDemand::factory()->create([
            'term_offering_id' => $offering->id,
            'course_component_id' => $component->id,
            'section_delivery_group_id' => $deliveryGroup->id,
            'source_snapshot' => [
                'room_type_requirement' => Room::TypeLaboratory,
                'required_room_feature_keys' => ['AIR_CONDITIONED'],
            ],
        ]);
        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
        ]);
        $version = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'room-guard-v1-'.$term->id),
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);
        SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $version->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $registrar->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);

        Livewire::actingAs($registrar)
            ->test(SchoolResources::class)
            ->callTableAction('editRoom', $room, data: [
                'code' => 'CHEM01',
                'name' => 'Chemistry Lab Updated',
                'building' => 'Science Wing',
                'room_type' => Room::TypeLectureRoom,
                'capacity' => 20,
                'is_active' => false,
                'features' => [],
            ])
            ->assertHasTableActionErrors(['is_active', 'room_type', 'capacity', 'features']);

        $room->refresh();
        $this->assertTrue((bool) $room->is_active);
        $this->assertSame(Room::TypeLaboratory, $room->room_type);
        $this->assertSame(40, (int) $room->capacity);
    }

    public function test_term_overview_opens_on_academic_years_and_drills_down_into_scoped_terms(): void
    {
        $registrar = $this->registrar();
        $ay1 = AcademicYear::factory()->create([
            'label' => 'AY 2028-2029',
            'starts_on' => '2028-08-01',
            'ends_on' => '2029-05-31',
            'state' => AcademicYear::StateActive,
        ]);
        $ay2 = AcademicYear::factory()->create([
            'label' => 'AY 2029-2030',
            'starts_on' => '2029-08-01',
            'ends_on' => '2030-05-31',
            'state' => AcademicYear::StateDraft,
        ]);
        $termInAy1 = Term::factory()->for($ay1)->create([
            'label' => 'First Semester 2028',
            'starts_on' => '2028-08-10',
            'ends_on' => '2028-12-15',
            'state' => Term::StateActive,
        ]);
        $termInAy2 = Term::factory()->for($ay2)->create([
            'label' => 'First Semester 2029',
            'starts_on' => '2029-08-10',
            'ends_on' => '2029-12-15',
            'state' => Term::StateDraft,
        ]);

        // Root lands on Academic Years (no segmented Terms | Academic years switcher).
        Livewire::actingAs($registrar)
            ->test(TermOverview::class)
            ->assertOk()
            ->assertSee('AY 2028-2029')
            ->assertSee('AY 2029-2030')
            ->assertDontSee('Term Planning directory view')
            ->assertSeeHtml('href="'.e(TermOverview::getUrl(['academicYearId' => $ay1->id])).'"');

        // Drilling into AY 2028-2029 scopes the Terms table strictly to AY 2028-2029.
        Livewire::withQueryParams(['academicYearId' => $ay1->id])
            ->actingAs($registrar)
            ->test(TermOverview::class)
            ->assertOk()
            ->assertSee('AY 2028-2029')
            ->assertSee('First Semester 2028')
            ->assertDontSee('First Semester 2029')
            ->assertSeeHtml('href="'.e(TermCalendar::getUrl(['termId' => $termInAy1->id])).'"')
            ->call('backToAcademicYears')
            ->assertSet('academicYearId', null)
            ->assertSee('AY 2029-2030');

        // Invalid academicYearId returns 404.
        Livewire::withQueryParams(['academicYearId' => 999999])
            ->actingAs($registrar)
            ->test(TermOverview::class)
            ->assertStatus(404);
    }

    private function registrar(): User
    {
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        foreach ([
            'manage-admission-setup',
            'manage-academic-structure',
            'manage-term-planning',
            'manage-scheduling',
            'manage-schedules',
        ] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('base32secret3232');
        $user->acknowledgeRecoveryCodeStorage();

        return $user;
    }
}
