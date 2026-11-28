<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\AdjustCandidateMeeting;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\RecordTermCohort;
use App\Actions\Scheduling\ScheduleAssignmentRevalidationService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\ScheduleRevisionImpactService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Actions\Scheduling\TimetableConflictDiagnosticService;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\CurriculumVersionBatch;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\TimetableRevision;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class Issue93FacultyAvailabilityAndTimetableExpansionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_registrar_can_record_and_update_faculty_availability_with_audit_notification_and_stale_handling(): void
    {
        $registrar = $this->actingAsRegistrar();
        [$term, $package, $program, $curriculum] = $this->activeTermContext([1, 2, 3]);
        $faculty = $this->makeFaculty('Elena', 'Ramos');
        $otherFaculty = $this->makeFaculty('Marco', 'Cruz');

        [$course, $spec] = $this->createCourseWithComponent('CS 101', 'Programming 1', 3.0, 3.0, '2x90');
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => $term->type,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $this->createConfirmedSection($offering, $package, $spec, 'CS101-1A');

        FacultyQualification::factory()->create([
            'faculty_user_id' => $faculty->id,
            'course_id' => $course->id,
            'is_active' => true,
        ]);

        // Historical Superseded run must NOT be touched; active UnderReview run MUST become Stale.
        $historicalRun = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusSuperseded,
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'candidate_state' => 'Superseded',
        ]);
        $activeDraft = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'candidate_state' => 'UnderReview',
        ]);

        // Registrar edits without a reason must fail validation even on v1.
        try {
            app(RecordFacultyAvailabilityDeclaration::class)->execute(
                term: $term,
                faculty: $faculty,
                actor: $registrar,
                correctionReason: '',
                availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
            );
            $this->fail('Expected ValidationException when Registrar omits correction_reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correction_reason', $exception->errors());
        }

        // Another Faculty member cannot edit Elena's availability.
        try {
            app(RecordFacultyAvailabilityDeclaration::class)->execute(
                term: $term,
                faculty: $faculty,
                actor: $otherFaculty,
                correctionReason: 'Unauthorized attempt',
                availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
            );
            $this->fail('Expected 403 when another Faculty edits Elena availability.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        // Registrar edits Elena's availability via TermResources row action.
        Livewire::withQueryParams(['termId' => $term->id])
            ->test(TermResources::class)
            ->mountTableAction('editAvailability', 'faculty-'.$faculty->id)
            ->setTableActionData([
                'availability_mode' => FacultyAvailabilityDeclaration::ModeWindows,
                'teaching_windows' => [
                    ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
                    ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '12:00'],
                ],
                'correction_reason' => 'Faculty requested Mon/Wed morning schedule after cutoff',
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Availability updated for Elena Ramos (v1)');

        $v1 = FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->where('faculty_user_id', $faculty->id)
            ->sole();

        $this->assertSame(1, (int) $v1->version);
        $this->assertSame(FacultyAvailabilityDeclaration::ModeWindows, $v1->effectiveMode());
        $this->assertSame('Faculty requested Mon/Wed morning schedule after cutoff', $v1->correction_reason);

        $this->assertSame('Stale', $activeDraft->fresh()->candidate_state);
        $this->assertSame('Superseded', $historicalRun->fresh()->candidate_state);

        // Audit log records Registrar as causer distinct from Faculty.
        $audit = DB::table('activity_log')
            ->where('event', 'registrar_recorded_faculty_availability')
            ->where('subject_type', FacultyAvailabilityDeclaration::class)
            ->where('subject_id', $v1->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame((int) $registrar->id, (int) $audit->causer_id);

        // Affected Faculty receives a database notification.
        $this->assertGreaterThan(
            0,
            DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $faculty->id)
                ->count(),
        );
    }

    public function test_term_resources_includes_retired_continuing_curricula_and_batch_filters(): void
    {
        $this->actingAsRegistrar();
        [$term, $package, $program, $activeCurriculum] = $this->activeTermContext([1, 3], 'DIT93');
        $faculty = $this->makeFaculty('Paolo', 'Dizon');

        $oldYear = AcademicYear::factory()->create(['label' => '2025-2026', 'starts_on' => '2025-08-01', 'ends_on' => '2026-05-31']);
        $newYear = AcademicYear::factory()->create(['label' => '2026-2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31']);

        $retiredCurriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'DIT93 Legacy Curriculum',
            'state' => CurriculumVersion::StateSuperseded,
        ]);
        CurriculumVersionBatch::query()->create([
            'curriculum_version_id' => $retiredCurriculum->id,
            'academic_year_id' => $oldYear->id,
            'program_id' => $program->id,
        ]);
        CurriculumVersionBatch::query()->create([
            'curriculum_version_id' => $activeCurriculum->id,
            'academic_year_id' => $newYear->id,
            'program_id' => $program->id,
        ]);

        [$legacyCourse, $legacySpec] = $this->createCourseWithComponent('DIT93 201', 'Legacy Systems', 3.0, 3.0, '2x90');
        CurriculumEntry::factory()->for($retiredCurriculum, 'curriculumVersion')->for($legacySpec, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_type' => $term->type,
        ]);

        [$newCourse, $newSpec] = $this->createCourseWithComponent('DIT93 101', 'Modern Computing', 3.0, 3.0, '2x90');
        $newEntry = CurriculumEntry::factory()->for($activeCurriculum, 'curriculumVersion')->for($newSpec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => $term->type,
        ]);
        $offering = TermOffering::factory()->for($term)->for($newEntry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $this->createConfirmedSection($offering, $package, $newSpec, 'DIT93-1A');

        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class);

        // Under 'needed' filter, helper text clarifies that unoffered subjects are hidden.
        $page->mountAction('assignSubjects')
            ->setActionData([
                'faculty_user_id' => $faculty->id,
                'program_filter' => 'needed',
            ])
            ->assertMountedActionModalSee('Showing only subjects offered this term')
            ->assertMountedActionModalSee('DIT93 101');

        // Switching to the retired continuing curriculum batch filter exposes DIT93 201 with its batch tag.
        $page->setActionData([
            'program_filter' => 'curriculum_batch:'.$retiredCurriculum->id.':'.$oldYear->id,
        ])
            ->assertMountedActionModalSee('DIT93 201 · Legacy Systems')
            ->assertMountedActionModalSee('DIT93 · Batch 2025');
    }

    public function test_validation_emits_outside_faculty_availability_and_diagnostics_flag_stale_availability_drift(): void
    {
        $registrar = $this->actingAsRegistrar();
        [$term, $package, $program, $curriculum] = $this->activeTermContext([1, 2, 3]);
        $faculty = $this->makeFaculty('Nadine', 'Santos');
        $room = Room::factory()->create(['code' => 'RM-101', 'room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);

        [$course, $spec, $demand, $run, $row] = $this->createDraftRunWithSingleMeeting(
            registrar: $registrar,
            term: $term,
            package: $package,
            program: $program,
            curriculum: $curriculum,
            faculty: $faculty,
            room: $room,
            declaredWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
            scheduledDay: 1,
            scheduledStart: '08:00:00',
            scheduledEnd: '09:30:00',
        );

        // Moving to Tue 14:00-15:30 (outside declared Mon 08:00-12:00) emits dedicated outside_faculty_availability finding.
        $validation = app(ScheduleAssignmentRevalidationService::class)->validateCandidateSet($run, [[
            'scheduling_demand_id' => (int) $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => (int) $faculty->id,
            'room_id' => (int) $room->id,
            'day_of_week' => 2,
            'starts_at' => '14:00:00',
            'ends_at' => '15:30:00',
            'time_block_key' => 'D2-1400',
            'status' => CandidateScheduleRow::StatusOk,
        ]]);

        $this->assertFalse($validation->passes());
        $finding = $validation->blockingFindings()[0] ?? [];
        $this->assertSame('outside_faculty_availability', $finding['code'] ?? null);
        $this->assertStringContainsString('Nadine Santos', (string) ($finding['message'] ?? ''));
        $this->assertStringContainsString('Mon 08:00', (string) ($finding['message'] ?? ''));
        $this->assertStringNotContainsString('captured recurring scheduling block', (string) ($finding['message'] ?? ''));

        // Narrowing faculty availability marks draft stale and diagnostics flag stale-availability-drift.
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $registrar,
            correctionReason: 'Shifted to afternoon only',
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '17:00']],
        );

        $diagnosis = app(TimetableConflictDiagnosticService::class)->diagnose($run->fresh(), $term);
        $this->assertTrue($diagnosis['has_conflicts']);
        $driftItem = collect($diagnosis['items'])->firstWhere('category', 'Faculty availability change');
        $this->assertNotNull($driftItem);
        $this->assertStringContainsString('Nadine Santos', implode(' ', $driftItem['involved'] ?? []));

        // ReadyTermPlanningProjection copy directs Registrar to Edit availability in Faculty & rooms.
        $demand->update(['fixed_faculty_user_id' => $faculty->id]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $registrar,
            correctionReason: 'Medical leave',
            availabilityMode: FacultyAvailabilityDeclaration::ModeCannotTeach,
        );
        $projection = app(ReadyTermPlanningProjection::class)->forTerm($term);
        $fixedBlocker = collect($projection['blockers'])->firstWhere('code', 'fixed_faculty_cannot_teach');
        $this->assertNotNull($fixedBlocker);
        $this->assertStringContainsString('Edit availability in Faculty & rooms', (string) $fixedBlocker['next_action']);
    }

    public function test_draft_move_and_expand_availability_is_atomic_monotonic_and_rolls_back_on_hard_conflict(): void
    {
        $registrar = $this->actingAsRegistrar();
        [$term, $package, $program, $curriculum] = $this->activeTermContext([1, 2, 3]);
        $faculty = $this->makeFaculty('Tessa', 'Mendoza');
        $noResponseFaculty = $this->makeFaculty('Luis', 'Pineda');
        $cannotTeachFaculty = $this->makeFaculty('Grace', 'Lim');
        $zeroUnitFaculty = $this->makeFaculty('Ben', 'Ocampo');
        $room = Room::factory()->create(['code' => 'RM-201', 'room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);

        [$course, $spec, $demand, $run, $row] = $this->createDraftRunWithSingleMeeting(
            registrar: $registrar,
            term: $term,
            package: $package,
            program: $program,
            curriculum: $curriculum,
            faculty: $faculty,
            room: $room,
            declaredWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
            scheduledDay: 1,
            scheduledStart: '08:00:00',
            scheduledEnd: '09:30:00',
        );

        foreach ([$noResponseFaculty, $cannotTeachFaculty, $zeroUnitFaculty] as $extraTeacher) {
            FacultyQualification::factory()->create([
                'faculty_user_id' => $extraTeacher->id,
                'course_id' => $course->id,
                'is_active' => true,
            ]);
        }

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $cannotTeachFaculty,
            actor: $cannotTeachFaculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeCannotTeach,
        );
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $zeroUnitFaculty,
            actor: $zeroUnitFaculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
        );
        FacultyTermLoadOverride::factory()->create([
            'term_id' => $term->id,
            'faculty_user_id' => $zeroUnitFaculty->id,
            'default_max_units_snapshot' => 0,
            'approved_overload_units' => 0,
            'reason' => 'Administrative leave',
            'is_active' => true,
        ]);

        // Add an institution recurring CalendarEvent block on Wed 14:00-16:00 to verify calendarEventBlocks payload & hard block.
        CalendarEvent::query()->create([
            'term_id' => $term->id,
            'event_type' => CalendarEvent::TypeUnavailable,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'day_of_week' => 3,
            'starts_at' => '14:00:00',
            'ends_at' => '16:00:00',
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
            'authority' => 'Faculty Assembly',
        ]);

        // Restore draft run to UnderReview after recording unrelated extra-teacher fixtures above.
        $run->refresh();
        $run->forceFill(['candidate_state' => 'UnderReview'])->save();

        // Verify TermTimetable interactive context payload includes full availability, NoResponse, and calendarEventBlocks.
        $timetableComponent = Livewire::actingAs($registrar)
            ->test(TermTimetable::class, [
                'termId' => $term->id,
                'timetableState' => 'draft',
                'selectedRunId' => $run->id,
            ]);

        $interactiveContext = $timetableComponent->viewData('interactiveContext');
        $this->assertTrue((bool) ($interactiveContext['editable'] ?? false));
        $this->assertTrue($interactiveContext['editable'], 'Expected draft timetable to be editable: '.json_encode([
            'timetableState' => $timetableComponent->get('timetableState'),
            'selectedRunId' => $timetableComponent->get('selectedRunId'),
            'termId' => $timetableComponent->get('termId'),
            'runStatus' => $run->fresh()->status,
            'runCandidateState' => $run->fresh()->candidate_state,
            'readOnly' => $interactiveContext['readOnly'] ?? null,
            'isOfficial' => $interactiveContext['isOfficial'] ?? null,
        ]));
        $this->assertTrue($interactiveContext['teacherAvailability'][$faculty->id]['canExpand']);
        $this->assertSame('ModeWindows', $interactiveContext['teacherAvailability'][$faculty->id]['status']);
        $this->assertStringContainsString('Mon 8:00 AM', $interactiveContext['teacherAvailability'][$faculty->id]['declaredSummary']);
        $this->assertFalse($interactiveContext['teacherAvailability'][$noResponseFaculty->id]['hasDeclaration']);
        $this->assertSame('NoResponse', $interactiveContext['teacherAvailability'][$noResponseFaculty->id]['status']);
        $this->assertFalse($interactiveContext['teacherAvailability'][$noResponseFaculty->id]['canExpand']);
        $this->assertNotEmpty($interactiveContext['calendarEventBlocks']);

        // 1. Ineligible teachers (CannotTeach, 0-unit, NoResponse) are hard-blocked from expansion.
        foreach ([$cannotTeachFaculty, $zeroUnitFaculty, $noResponseFaculty] as $ineligible) {
            $beforeCount = FacultyAvailabilityDeclaration::query()->where('term_id', $term->id)->count();
            try {
                app(AdjustCandidateMeeting::class)->execute(
                    requestedRow: $row,
                    actor: $registrar,
                    assignment: [
                        'faculty_user_id' => $ineligible->id,
                        'day_of_week' => 2,
                        'starts_at' => '14:00:00',
                        'ends_at' => '15:30:00',
                    ],
                    reason: 'Trying ineligible expansion',
                    expandTeacherAvailability: true,
                    availabilityExpansionReason: 'Trying ineligible expansion',
                );
                $this->fail('Expected ineligible teacher '.$ineligible->name.' to be blocked.');
            } catch (ValidationException) {
                $this->assertSame($beforeCount, FacultyAvailabilityDeclaration::query()->where('term_id', $term->id)->count());
            }
        }

        // 2. Hard conflict rollback: moving into daily break (12:00-13:00) MUST reject & roll back v+1 and send zero notifications!
        $beforeDecls = FacultyAvailabilityDeclaration::query()->where('term_id', $term->id)->where('faculty_user_id', $faculty->id)->count();
        $beforeNotifs = DB::table('notifications')->where('notifiable_id', $faculty->id)->count();
        try {
            app(AdjustCandidateMeeting::class)->execute(
                requestedRow: $row,
                actor: $registrar,
                assignment: [
                    'faculty_user_id' => $faculty->id,
                    'room_id' => $room->id,
                    'day_of_week' => 1,
                    'starts_at' => '12:00:00',
                    'ends_at' => '13:30:00',
                ],
                reason: 'Break overlap test',
                expandTeacherAvailability: true,
                availabilityExpansionReason: 'Break overlap test',
            );
            $this->fail('Expected daily break hard conflict to roll back availability expansion.');
        } catch (ValidationException) {
            $this->assertSame($beforeDecls, FacultyAvailabilityDeclaration::query()->where('term_id', $term->id)->where('faculty_user_id', $faculty->id)->count());
            $this->assertSame($beforeNotifs, DB::table('notifications')->where('notifiable_id', $faculty->id)->count());
        }

        // 3. Valid 1-step audited Move & Expand to Tue 14:00-15:30 via TermTimetable::moveAndExpandTeacherAvailability.
        $originalSnapshotAvailability = $run->input_snapshot['faculty_availability'] ?? [];

        $timetableComponent
            ->call('moveAndExpandTeacherAvailability', $row->id, 2, '14:00', $room->id, $faculty->id, 'Confirmed with teacher to cover Tue afternoon')
            ->assertHasNoErrors()
            ->assertNotified('Meeting moved & teacher availability expanded');

        $v2 = FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->where('faculty_user_id', $faculty->id)
            ->latest('version')
            ->firstOrFail();

        $this->assertSame(2, (int) $v2->version);
        $this->assertCount(2, $v2->normalizedTeachingWindows());
        // Original Mon 08:00-12:00 preserved + Tue 14:00-15:30 added monotonically.
        $this->assertFalse($v2->conflictsWithMeeting(1, '08:00:00', '12:00:00'));
        $this->assertFalse($v2->conflictsWithMeeting(2, '14:00:00', '15:30:00'));

        // Successor run is UnderReview (not Stale) and refreshed its input_snapshot while source run stayed immutable.
        $successorRun = ScheduleGenerationRun::query()
            ->where('term_id', $term->id)
            ->where('status', ScheduleGenerationRun::StatusUnderReview)
            ->latest('id')
            ->firstOrFail();
        $this->assertNotSame($run->id, $successorRun->id);
        $this->assertSame('UnderReview', $successorRun->candidate_state);
        $this->assertSame($originalSnapshotAvailability, $run->fresh()->input_snapshot['faculty_availability'] ?? []);
        $successorAvail = collect($successorRun->input_snapshot['faculty_availability'] ?? [])
            ->firstWhere('faculty_user_id', $faculty->id);
        $this->assertSame(2, (int) ($successorAvail['declaration_version'] ?? 0));

        // Audit log + Faculty DB notification recorded.
        $this->assertTrue(
            DB::table('activity_log')
                ->where('event', 'faculty_availability_expanded_for_schedule')
                ->where('subject_id', $v2->id)
                ->where('causer_id', $registrar->id)
                ->exists(),
        );
        $this->assertGreaterThan($beforeNotifs, DB::table('notifications')->where('notifiable_id', $faculty->id)->count());
    }

    public function test_published_revision_expands_availability_atomically_and_defers_mutation_while_student_review_is_pending(): void
    {
        $registrar = $this->actingAsRegistrar();
        [$term, $package, $program, $curriculum] = $this->activeTermContext([1, 2, 3]);
        $faculty = $this->makeFaculty('Sofia', 'Villanueva');
        $room = Room::factory()->create(['code' => 'RM-301', 'room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);

        [$course, $spec, $demand, $draftRun] = $this->createDraftRunWithSingleMeeting(
            registrar: $registrar,
            term: $term,
            package: $package,
            program: $program,
            curriculum: $curriculum,
            faculty: $faculty,
            room: $room,
            declaredWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
            scheduledDay: 1,
            scheduledStart: '08:00:00',
            scheduledEnd: '09:30:00',
        );

        // Publish draftRun so we have an active official timetable & SectionMeeting.
        $draftRun->update(['candidate_state' => 'Accepted']);
        $publishedRun = app(SchedulePublishService::class)->publish($draftRun, $registrar, 'Initial official release');
        $meeting = SectionMeeting::query()
            ->where('schedule_run_id', $publishedRun->id)
            ->where('state', SectionMeeting::StateActive)
            ->sole();

        // Preview without expansion blocks with outside_faculty_availability; preview with simulateAvailabilityExpansion passes.
        $rawImpact = app(ScheduleRevisionImpactService::class)->preview(
            $publishedRun,
            ScheduleRevisionEvent::ChangeTime,
            [[
                'section_meeting_id' => $meeting->id,
                'day_of_week' => 2,
                'starts_at' => '14:00:00',
                'ends_at' => '15:30:00',
            ]],
            simulateAvailabilityExpansion: false,
        );
        $this->assertFalse($rawImpact->passes());
        $this->assertSame('outside_faculty_availability', $rawImpact->findings()[0]['code'] ?? null);

        $simImpact = app(ScheduleRevisionImpactService::class)->preview(
            $publishedRun,
            ScheduleRevisionEvent::ChangeTime,
            [[
                'section_meeting_id' => $meeting->id,
                'day_of_week' => 2,
                'starts_at' => '14:00:00',
                'ends_at' => '15:30:00',
            ]],
            simulateAvailabilityExpansion: true,
        );
        $this->assertTrue($simImpact->passes());

        // Verify PublishedScheduleRevisionForm modal exposes inline teacher availability summary in Step 2
        // and offers the 1-step "Move & expand teacher's availability (outside original request)" checkbox.
        Livewire::actingAs($registrar)
            ->test(TermTimetable::class, [
                'termId' => $term->id,
                'timetableState' => 'official',
            ])
            ->mountAction('revisePublishedSchedule', ['section_meeting_id' => $meeting->id])
            ->setActionData([
                'change_type' => ScheduleRevisionEvent::ChangeTime,
                'day_of_week' => 2,
                'starts_at' => '14:00',
                'ends_at' => '15:30',
            ])
            ->assertMountedActionModalSee('Sofia Villanueva · Availability')
            ->assertMountedActionModalSee('Mon 8:00 AM–12:00 PM')
            ->assertMountedActionModalSee("Move & expand teacher's availability (outside original request)")
            ->assertMountedActionModalDontSee('Manual override');

        // Case A: Add an in-progress student with a StateConfirmed registration proposal on this section.
        // Calling revise(..., expandTeacherAvailability: true) must park a Draft TimetableRevision WITHOUT mutating FacultyAvailabilityDeclaration!
        $section = $demand->sectionDeliveryGroup->section;
        $student = User::factory()->create(['status' => User::StatusActive]);
        $studentProfile = StudentProfile::factory()->for($student, 'user')->for($program)->for($curriculum, 'curriculumVersion')->create();
        $enrollment = Enrollment::factory()->for($term)->create([
            'student_profile_id' => $studentProfile->id,
            'credential_user_id' => $student->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $publishedVersion = PublishedTimetableVersion::query()
            ->where('schedule_run_id', $publishedRun->id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->firstOrFail();
        $proposal = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'curriculum_version_id' => $curriculum->id,
            'published_timetable_version_id' => $publishedVersion->id,
            'version' => 1,
            'state' => RegistrationProposalVersion::StateConfirmed,
            'prepared_by' => $registrar->id,
        ]);
        $enrollment->update(['current_proposal_version_id' => $proposal->id]);
        RegistrationProposalItem::factory()->create([
            'registration_proposal_version_id' => $proposal->id,
            'term_offering_id' => $demand->term_offering_id,
            'section_id' => $section->id,
        ]);

        try {
            app(PublishedScheduleRevisionService::class)->revise(
                run: $publishedRun,
                actor: $registrar,
                changeType: ScheduleRevisionEvent::ChangeTime,
                changes: [[
                    'section_meeting_id' => $meeting->id,
                    'day_of_week' => 2,
                    'starts_at' => '14:00:00',
                    'ends_at' => '15:30:00',
                ]],
                reason: 'Move to Tue afternoon with teacher agreement',
                authorityReference: 'MEMO-2026-93',
                expandTeacherAvailability: true,
            );
            $this->fail('Expected pending student registration impact review to park Draft revision.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Resolve every affected enrollment registration placement', (string) collect($exception->errors())->flatten()->first());
        }

        // CRITICAL INVARIANT: FacultyAvailabilityDeclaration is STILL v1 (never mutated prematurely while Draft is pending!).
        $this->assertSame(
            1,
            FacultyAvailabilityDeclaration::query()
                ->where('term_id', $term->id)
                ->where('faculty_user_id', $faculty->id)
                ->count(),
        );

        $draftRevision = TimetableRevision::query()
            ->where('term_id', $term->id)
            ->where('state', TimetableRevision::StateDraft)
            ->sole();

        // Now resolve the student impact review and publish the Draft revision -> expands v2 + publishes atomically!
        RegistrationCaseEvent::query()->create([
            'enrollment_id' => $enrollment->id,
            'sequence' => ((int) $enrollment->registrationEvents()->max('sequence')) + 1,
            'event_type' => 'TimetableRevisionImpactReviewResolved',
            'from_outcome' => $enrollment->canonical_outcome,
            'to_outcome' => $enrollment->canonical_outcome,
            'reason' => 'Student schedule checked and cleared',
            'authority_reference' => 'timetable-revision:'.$draftRevision->id,
            'actor_id' => $registrar->id,
            'recorded_at' => now(),
        ]);

        $events = app(PublishedScheduleRevisionService::class)->publishDraftRevision(
            $publishedRun,
            $draftRevision,
            $registrar,
        );

        $this->assertCount(1, $events);
        $this->assertSame(TimetableRevision::StatePublished, $draftRevision->fresh()->state);

        $v2 = FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->where('faculty_user_id', $faculty->id)
            ->latest('version')
            ->firstOrFail();
        $this->assertSame(2, (int) $v2->version);
        $this->assertFalse($v2->conflictsWithMeeting(1, '08:00:00', '12:00:00'));
        $this->assertFalse($v2->conflictsWithMeeting(2, '14:00:00', '15:30:00'));

        $latestVersion = PublishedTimetableVersion::query()
            ->where('term_id', $term->id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('version')
            ->firstOrFail();
        $this->assertSame(2, (int) $latestVersion->version);
        $this->assertSame(2, (int) $latestVersion->meetings()->sole()->day_of_week);
    }

    public function test_legacy_unavailability_monotonic_expansion_subtracts_only_target_slot(): void
    {
        $registrar = $this->actingAsRegistrar();
        [$term] = $this->activeTermContext([1, 2, 3]);
        $faculty = $this->makeFaculty('Arturo', 'Bautista');

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            declaration: FacultyAvailabilityDeclaration::DeclarationAvailable,
            hardUnavailability: [
                ['day_of_week' => 2, 'starts_at' => '13:00:00', 'ends_at' => '17:00:00'],
                ['day_of_week' => 3, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
            ],
        );

        $expanded = app(RecordFacultyAvailabilityDeclaration::class)->expandForScheduledSlot(
            term: $term,
            faculty: $faculty,
            actor: $registrar,
            dayOfWeek: 2,
            startsAt: '14:00:00',
            endsAt: '15:30:00',
            reason: 'Agreed to cover Tue 2:00-3:30 PM',
        );

        $this->assertInstanceOf(FacultyAvailabilityDeclaration::class, $expanded);
        $this->assertSame(2, (int) $expanded->version);
        // Target slot is now open while surrounding Tue blocks & Wed block remain preserved!
        $this->assertFalse($expanded->conflictsWithMeeting(2, '14:00:00', '15:30:00'));
        $this->assertTrue($expanded->conflictsWithMeeting(2, '13:00:00', '14:00:00'));
        $this->assertTrue($expanded->conflictsWithMeeting(2, '15:30:00', '17:00:00'));
        $this->assertTrue($expanded->conflictsWithMeeting(3, '08:00:00', '12:00:00'));
    }

    /**
     * @param  list<array{day_of_week: int, start_time: string, end_time: string}>  $declaredWindows
     * @return array{Course, CourseSpecification, SchedulingDemand, ScheduleGenerationRun, CandidateScheduleRow}
     */
    private function createDraftRunWithSingleMeeting(
        User $registrar,
        Term $term,
        TermCalendarPackage $package,
        Program $program,
        CurriculumVersion $curriculum,
        User $faculty,
        Room $room,
        array $declaredWindows,
        int $scheduledDay,
        string $scheduledStart,
        string $scheduledEnd,
    ): array {
        [$course, $spec] = $this->createCourseWithComponent('IT 201', 'Database Systems', 3.0, 1.5, '1x90');
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_type' => $term->type,
        ]);
        $cohort = app(RecordTermCohort::class)->execute(
            term: $term,
            actor: $registrar,
            data: [
                'curriculum_version_id' => $curriculum->id,
                'reference' => $program->code.' 2A',
                'year_level' => 2,
                'confirmed_count' => 30,
            ],
        );
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 30,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = $this->createConfirmedSection($offering, $package, $spec, 'IT201-2A');
        SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'IT201-2A',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $section->cohorts()->attach($cohort->id, ['expected_count' => 30]);

        FacultyQualification::factory()->create([
            'faculty_user_id' => $faculty->id,
            'course_id' => $course->id,
            'is_active' => true,
        ]);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: $declaredWindows,
        );

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $demand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->sole();

        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'candidate_state' => 'UnderReview',
            'input_snapshot' => [],
            'input_hash' => '',
            'published_by' => null,
            'published_at' => null,
            'publication_version' => null,
        ]);
        app(ScheduleSolverSnapshotService::class)->captureForRun($run);
        $run->refresh();

        $row = CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => $scheduledDay,
            'starts_at' => $scheduledStart,
            'ends_at' => $scheduledEnd,
            'time_block_key' => 'D'.$scheduledDay.'-'.str_replace(':', '', substr($scheduledStart, 0, 5)),
            'status' => CandidateScheduleRow::StatusOk,
        ]);

        return [$course, $spec, $demand, $run, $row];
    }

    private function actingAsRegistrar(): User
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->syncPermissions([
            Permission::findOrCreate('manage-schedules', 'web'),
            Permission::findOrCreate('approve-documents', 'web'),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);

        return $user;
    }

    private function makeFaculty(string $firstName, string $lastName): User
    {
        $role = Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $faculty = User::factory()->create([
            'first_name' => $firstName,
            'middle_name' => null,
            'last_name' => $lastName,
            'name' => $firstName.' '.$lastName,
            'status' => User::StatusActive,
        ]);
        $faculty->assignRole($role);

        return $faculty;
    }

    /**
     * @param  list<int>  $days
     * @return array{Term, TermCalendarPackage, Program, CurriculumVersion}
     */
    private function activeTermContext(array $days = [1, 3], string $programCode = 'BSIT93'): array
    {
        $term = Term::factory()->create([
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
            'default_max_units' => 24,
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        foreach ($days as $day) {
            TermTeachingGridRow::factory()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
            ]);
        }

        $program = Program::factory()->create([
            'code' => $programCode,
            'name' => 'Bachelor of Science in Information Technology',
        ]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => $programCode.' Curriculum 2026',
            'state' => CurriculumVersion::StateActive,
        ]);

        return [$term, $package, $program, $curriculum];
    }

    /** @return array{Course, CourseSpecification} */
    private function createCourseWithComponent(
        string $code,
        string $title,
        float $units,
        float $weeklyHours,
        string $pattern,
    ): array {
        $course = Course::factory()->create(['code' => $code]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => $title,
            'state' => CourseSpecification::StateActive,
            'credit_units' => $units,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        CourseComponent::factory()->for($spec, 'courseSpecification')->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => $weeklyHours,
            'meeting_pattern' => $pattern,
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
        ]);

        return [$course, $spec];
    }

    private function createConfirmedSection(
        TermOffering $offering,
        TermCalendarPackage $package,
        CourseSpecification $spec,
        string $code,
    ): Section {
        return Section::factory()->for($offering, 'termOffering')->create([
            'code' => $code,
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $spec->id,
            'capacity' => 30,
            'state' => Section::StatePlanned,
            'confirmed_at' => now(),
        ]);
    }
}
