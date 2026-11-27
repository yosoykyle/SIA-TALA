<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Scheduling\ConfirmClassOffering;
use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\FacultyAvailabilityStatusProjection;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\PrepareClassOffering;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeadline;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\RecordTermCohort;
use App\Actions\Scheduling\ScheduleAssignmentValidationService;
use App\Actions\Scheduling\ScheduleSolverSnapshotService;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\OperationalEvent;
use App\Models\Program;
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
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class Issue93AvailabilityFirstBackendTest extends TestCase
{
    use DatabaseTransactions;

    private int $seq = 0;

    public function test_chosen_windows_compile_exact_complement_never_widen_and_flag_grid_review(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $calendar = $this->activeCalendar($term, [
            1 => ['08:00:00', '17:00:00', [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
            3 => ['09:00:00', '15:00:00', []],
        ]);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $declaration = app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
                ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '15:00'],
            ],
        );

        $this->assertSame(FacultyAvailabilityDeclaration::DeclarationAvailable, $declaration->declaration);
        $this->assertSame(FacultyAvailabilityDeclaration::ModeWindows, $declaration->effectiveMode());
        $this->assertSame('Choose teaching times', $declaration->modeLabel());
        $this->assertFalse($declaration->needsGridReview($calendar));
        $this->assertSame([], $declaration->windowsOutsideGrid($calendar));

        $this->assertSame([
            ['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00'],
            ['day_of_week' => 1, 'starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ['day_of_week' => 1, 'starts_at' => '15:00:00', 'ends_at' => '17:00:00'],
            ['day_of_week' => 3, 'starts_at' => '09:00:00', 'ends_at' => '15:00:00'],
        ], $declaration->compileUnavailableBlocks($calendar));

        // Later grid widening on Day 1 (07:00-20:00) and added break (10:00-11:00) never mutates or widens chosen windows.
        $calendar->teachingGridRows()->where('day_of_week', 1)->update([
            'starts_at' => '07:00:00',
            'ends_at' => '20:00:00',
            'breaks' => [
                ['starts_at' => '10:00:00', 'ends_at' => '11:00:00'],
                ['starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ],
        ]);
        // Remove Day 3 and verify stored windows remain untouched while Day 1 widened bounds stay blocked.
        // Window 09:00-12:00 spans the 10:00-11:00 break (leaving 09:00-10:00 and 11:00-12:00 usable), so it is NOT flagged.
        $calendar->teachingGridRows()->where('day_of_week', 3)->delete();
        $calendar->unsetRelation('teachingGridRows');

        $this->assertFalse($declaration->needsGridReview($calendar));
        $this->assertSame([], $declaration->windowsOutsideGrid($calendar));
        $this->assertSame([
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '15:00'],
        ], $declaration->fresh()->normalizedTeachingWindows());

        $this->assertSame([
            ['day_of_week' => 1, 'starts_at' => '07:00:00', 'ends_at' => '09:00:00'],
            ['day_of_week' => 1, 'starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ['day_of_week' => 1, 'starts_at' => '15:00:00', 'ends_at' => '20:00:00'],
        ], $declaration->compileUnavailableBlocks($calendar));
    }

    public function test_record_declaration_validates_windows_requires_faculty_actor_and_stales_runs_on_first_response(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $this->activeCalendar($term, [
            1 => ['08:00:00', '17:00:00', [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
        ]);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'UnderReview',
        ]);

        try {
            app(RecordFacultyAvailabilityDeclaration::class)->execute(
                term: $term,
                faculty: $faculty,
                actor: $registrar,
                availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
            );
            $this->fail('Non-faculty actor must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        foreach ([
            'empty windows' => [],
            'non-30-min aligned' => [['day_of_week' => 1, 'start_time' => '08:15', 'end_time' => '10:00']],
            'outside grid day' => [['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '10:00']],
            'outside grid hours' => [['day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '10:00']],
            'wholly inside break' => [['day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '13:00']],
        ] as $case => $invalidWindows) {
            try {
                app(RecordFacultyAvailabilityDeclaration::class)->execute(
                    term: $term,
                    faculty: $faculty,
                    actor: $faculty,
                    availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
                    teachingWindows: $invalidWindows,
                );
                $this->fail("Invalid window case [{$case}] should fail validation.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('teaching_windows', $exception->errors(), $case);
            }
        }

        // First response (v1) after a generation run already exists MUST mark the run stale.
        $v1 = app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
        );

        $this->assertSame(1, $v1->version);
        $this->assertSame('Stale', $run->fresh()->candidate_state);
    }

    public function test_official_impact_detection_and_unified_status_projection_cover_registrar_and_faculty(): void
    {
        Mail::fake();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $facultyA = $this->staff(User::StaffRoleFaculty);
        $facultyB = $this->staff(User::StaffRoleFaculty);
        $facultyC = $this->staff(User::StaffRoleFaculty);
        $fixture = $this->classFixture($registrar, [$facultyA, $facultyB, $facultyC]);

        app(RecordFacultyAvailabilityDeadline::class)->execute(
            $fixture['term'],
            $registrar,
            '2026-08-15 09:00:00',
            'REF-93',
            null,
            0,
        );
        $requests = app(FacultyAvailabilityRequestService::class)->request(
            $fixture['package'],
            [$facultyB->id, $facultyC->id],
            $registrar,
        );
        $requests->firstWhere('user_id', $facultyB->id)?->forceFill([
            'status' => OperationalEvent::StatusProcessed,
            'sent_at' => now(),
        ])->save();
        $requests->firstWhere('user_id', $facultyC->id)?->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'failed_at' => now(),
        ])->save();

        // Publish official meeting for Faculty A on Monday 08:00-09:00 and Tuesday 10:00-11:00.
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $fixture['term']->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['scheduling_demands' => [['scheduling_demand_id' => $fixture['demand']->id]]],
            'input_hash' => hash('sha256', 'pub-93-'.uniqid()),
            'solver_version' => 'test-93',
            'published_by' => $registrar->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $version = PublishedTimetableVersion::query()->create([
            'term_id' => $fixture['term']->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'PUB-93',
            'publication_reason' => 'Initial official schedule',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'ver-93-'.uniqid()),
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);
        $monMeeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $version->id,
            'scheduling_demand_id' => $fixture['demand']->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $facultyA->id,
            'room_id' => $fixture['room']->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
        $tueMeeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $version->id,
            'scheduling_demand_id' => $fixture['demand']->id,
            'meeting_sequence' => 2,
            'faculty_user_id' => $facultyA->id,
            'room_id' => $fixture['room']->id,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);

        // Faculty A chooses ONLY Monday 08:00-12:00 -> Tuesday meeting conflicts; Monday meeting does NOT.
        $declaration = app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $fixture['term'],
            faculty: $facultyA,
            actor: $facultyA,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
        );

        $log = DB::table('activity_log')
            ->where('event', 'faculty_availability_revision_required')
            ->where('subject_id', $declaration->id)
            ->first();
        $this->assertNotNull($log);
        $props = json_decode($log->properties, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$tueMeeting->id], $props['affected_section_meeting_ids']);
        $this->assertSame($fixture['course']->code, $props['affected_meetings'][0]['course_code']);
        $this->assertSame($fixture['section']->code, $props['affected_meetings'][0]['section_code']);
        $this->assertSame(1, $props['declaration_version']);

        // Also record a ScheduleRevisionEvent for Faculty A on Monday meeting.
        ScheduleRevisionEvent::query()->create([
            'term_id' => $fixture['term']->id,
            'section_meeting_id' => $monMeeting->id,
            'change_type' => ScheduleRevisionEvent::ChangeRoom,
            'reason' => 'Moved to lab room',
            'effective_date' => '2026-08-20',
            'changed_by' => $registrar->id,
            'old_snapshot_json' => ['faculty_user_id' => $facultyA->id, 'day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00'],
            'new_snapshot_json' => ['faculty_user_id' => $facultyA->id, 'day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00'],
            'affected_student_count' => 10,
            'affected_faculty_count' => 1,
        ]);

        $projection = app(FacultyAvailabilityStatusProjection::class);
        $termStatus = $projection->forTerm($fixture['term']);

        $this->assertSame(3, $termStatus['total_faculty']);
        $this->assertSame(1, $termStatus['replied_count']);
        $this->assertSame(2, $termStatus['no_response_count']);
        $this->assertSame(0, $termStatus['cannot_teach_count']);
        $this->assertSame(1, $termStatus['late_impact_count']);
        $this->assertSame('No response', $termStatus['faculty_rows'][$facultyB->id]['status_label']);
        $this->assertSame('Reminder sent', $termStatus['faculty_rows'][$facultyB->id]['reminder_status']);
        $this->assertSame('No response', $termStatus['faculty_rows'][$facultyC->id]['status_label']);
        $this->assertSame('Reminder failed', $termStatus['faculty_rows'][$facultyC->id]['reminder_status']);
        $this->assertTrue($termStatus['faculty_rows'][$facultyA->id]['has_official_impact']);
        $this->assertCount(1, $termStatus['unresolved_late_impacts']);

        $facultyView = $projection->forFaculty($fixture['term'], $facultyA);
        $this->assertTrue($facultyView['has_official_impact']);
        $this->assertCount(1, $facultyView['late_impact_meetings']);
        $this->assertCount(1, $facultyView['revision_events']);
        $this->assertCount(2, $facultyView['affected_meetings']);

        // Reassigning Tuesday meeting to Faculty B resolves the unresolved late impact.
        $tueMeeting->update(['faculty_user_id' => $facultyB->id]);
        $afterReassign = $projection->forTerm($fixture['term']);
        $this->assertSame(0, $afterReassign['late_impact_count']);
        $this->assertSame([], $afterReassign['unresolved_late_impacts']);
    }

    public function test_readiness_and_snapshot_and_validation_enforce_availability_first_parity(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $facultyA = $this->staff(User::StaffRoleFaculty);
        $facultyB = $this->staff(User::StaffRoleFaculty);
        $fixture = $this->classFixture($registrar, [$facultyA, $facultyB]);

        // Both teachers have No response -> generation blocked naming class, course, and recovery.
        $blocked = app(ReadyTermPlanningProjection::class)->forTerm($fixture['term']);
        $this->assertFalse($blocked['ready']);
        $uncovered = collect($blocked['blockers'])->firstWhere('code', 'faculty_demand_uncovered');
        $this->assertNotNull($uncovered);
        $this->assertStringContainsString($fixture['section']->code, $uncovered['reason']);
        $this->assertStringContainsString($fixture['course']->code, $uncovered['reason']);

        // Faculty A declares ModeWindows Mon 08:00-10:00; Faculty B stays No response.
        // Missing reply from Faculty B MUST NOT block generation!
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $fixture['term'],
            faculty: $facultyA,
            actor: $facultyA,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00']],
        );
        $ready = app(ReadyTermPlanningProjection::class)->forTerm($fixture['term']);
        $this->assertTrue($ready['ready'], json_encode($ready, JSON_THROW_ON_ERROR));

        // Fixed-faculty pointing to Faculty B (No response) MUST block generation.
        $fixture['group']->update(['delivery_override' => ['fixed_faculty_user_id' => $facultyB->id]]);
        app(GenerateSchedulingDemand::class)->forTerm($registrar, $fixture['term']);
        $fixedBlocked = app(ReadyTermPlanningProjection::class)->forTerm($fixture['term']);
        $this->assertFalse($fixedBlocked['ready']);
        $fixedMissing = collect($fixedBlocked['blockers'])->firstWhere('code', 'fixed_faculty_availability_missing');
        $this->assertNotNull($fixedMissing);
        $this->assertStringContainsString($facultyB->name, $fixedMissing['reason']);
        $this->assertStringContainsString($fixture['section']->code, $fixedMissing['reason']);

        // Remove fixed override and capture snapshot -> Faculty B (No response) MUST be excluded from eligible_faculty_user_ids!
        $fixture['group']->update(['delivery_override' => null]);
        app(GenerateSchedulingDemand::class)->forTerm($registrar, $fixture['term']);
        $run = ScheduleGenerationRun::factory()->create(['term_id' => $fixture['term']->id, 'input_snapshot' => []]);
        $snapshot = app(ScheduleSolverSnapshotService::class)->captureForRun($run);

        $demandSnapshot = $snapshot['scheduling_demands'][0];
        $this->assertSame([$facultyA->id], $demandSnapshot['eligible_faculty_user_ids']);

        // Independent Laravel validator MUST reject Faculty B (faculty_not_eligible) and Faculty A outside 08:00-10:00 (calendar_block_overlap),
        // while accepting Faculty A on Monday 08:00-09:00.
        $validator = app(ScheduleAssignmentValidationService::class);
        $baseAssignment = [
            'scheduling_demand_id' => $fixture['demand']->id,
            'term_offering_id' => $fixture['offering']->id,
            'section_id' => $fixture['section']->id,
            'section_delivery_group_id' => $fixture['group']->id,
            'subject_id' => $fixture['course']->id,
            'course_component_id' => $fixture['component']->id,
            'faculty_id' => $facultyA->id,
            'faculty_user_id' => $facultyA->id,
            'room_id' => $fixture['room']->id,
            'day' => 1,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'starts_at' => '08:00:00',
            'ends_at' => '09:00:00',
            'time_slot_id' => collect($snapshot['time_slots'])->firstWhere('time_block_key', 'D1-0800')['time_slot_id'],
            'time_block_reference' => 'D1-0800',
            'time_block_key' => 'D1-0800',
            'meeting_sequence' => 1,
            'meeting_pattern' => '1x60',
            'assignment_status' => 'ok',
            'violations' => [],
            'warnings' => [],
            'scores' => [],
            'soft_constraint_scores' => [],
        ];

        $validResult = $validator->validateCandidateAssignments($run, $snapshot, [$baseAssignment]);
        $this->assertTrue($validResult->passes(), json_encode($validResult->findings(), JSON_THROW_ON_ERROR));

        $outsideWindow = $baseAssignment;
        $outsideWindow['start_time'] = '10:00:00';
        $outsideWindow['starts_at'] = '10:00:00';
        $outsideWindow['end_time'] = '11:00:00';
        $outsideWindow['ends_at'] = '11:00:00';
        $outsideWindow['time_block_key'] = 'D1-1000';
        $outsideWindow['time_block_reference'] = 'D1-1000';
        $outsideWindow['time_slot_id'] = collect($snapshot['time_slots'])->firstWhere('time_block_key', 'D1-1000')['time_slot_id'];

        $outsideResult = $validator->validateCandidateAssignments($run, $snapshot, [$outsideWindow]);
        $this->assertFalse($outsideResult->passes());
        $this->assertContains('calendar_block_overlap', array_column($outsideResult->blockingFindings(), 'code'));

        $nonResponder = $baseAssignment;
        $nonResponder['faculty_id'] = $facultyB->id;
        $nonResponder['faculty_user_id'] = $facultyB->id;
        $nonResponderResult = $validator->validateCandidateAssignments($run, $snapshot, [$nonResponder]);
        $this->assertFalse($nonResponderResult->passes());
        $this->assertContains('faculty_not_eligible', array_column($nonResponderResult->blockingFindings(), 'code'));
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: list<array{starts_at: string, ends_at: string}>}>  $rows
     */
    private function activeCalendar(Term $term, array $rows): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);

        foreach ($rows as $day => [$startsAt, $endsAt, $breaks]) {
            $package->teachingGridRows()->create([
                'day_of_week' => $day,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'breaks' => $breaks,
            ]);
        }

        return $package->fresh('teachingGridRows');
    }

    /**
     * @param  list<User>  $facultyList
     * @return array{term: Term, package: TermCalendarPackage, course: Course, offering: TermOffering, section: Section, group: SectionDeliveryGroup, component: CourseComponent, demand: SchedulingDemand, room: Room}
     */
    private function classFixture(User $registrar, array $facultyList): array
    {
        $suffix = ++$this->seq;
        $term = Term::factory()->create([
            'label' => 'Issue93 Term '.$suffix,
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
        ]);
        $package = $this->activeCalendar($term, [
            1 => ['08:00:00', '17:00:00', [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']]],
            2 => ['08:00:00', '17:00:00', []],
        ]);
        $program = Program::factory()->create(['code' => 'P93'.$suffix]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'CS93'.$suffix]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Availability Systems '.$suffix,
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
            'credit_units' => 3.00,
        ]);
        $component = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 1.00,
            'meeting_pattern' => '1x60',
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => $term->type,
            'sequence' => 1,
        ]);
        $cohort = app(RecordTermCohort::class)->execute($term, $registrar, [
            'curriculum_version_id' => $curriculum->id,
            'reference' => 'COH93-'.$suffix,
            'confirmed_count' => 25,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 25,
            'state' => TermOffering::StatePendingScheduling,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'CLS93-'.$suffix,
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Group '.$suffix,
            'expected_count' => 25,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $prepared = app(PrepareClassOffering::class)->execute($term, $section, $registrar, [
            'class_reference' => 'CLS93-'.$suffix,
            'source' => Section::SourceRegular,
            'delivery_mode' => TermOffering::ModalityFaceToFace,
        ]);
        app(ConfirmClassOffering::class)->execute($prepared, $registrar, [$cohort->id => 25]);

        foreach ($facultyList as $faculty) {
            FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create(['is_active' => true]);
        }

        $room = Room::factory()->create([
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);

        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $demand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->sole();

        return [
            'term' => $term,
            'package' => $package,
            'course' => $course,
            'offering' => $offering,
            'section' => $prepared->fresh(),
            'group' => $group->fresh(),
            'component' => $component,
            'demand' => $demand,
            'room' => $room,
        ];
    }

    private function staff(string $roleName): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        if ($roleName === User::StaffRoleRegistrar) {
            $role->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
