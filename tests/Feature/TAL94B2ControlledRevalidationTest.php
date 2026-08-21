<?php

namespace Tests\Feature;

use App\Actions\Scheduling\CandidateScheduleRowReviewService;
use App\Actions\Scheduling\ScheduleAssignmentRevalidationService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Models\CalendarEvent;
use App\Models\CandidateScheduleRow;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94B2ControlledRevalidationTest extends TestCase
{
    use DatabaseTransactions;

    private int $contextCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), [
            'tala_db',
            'demo_tala_db',
            'tala_test_codex',
        ]);

        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_candidate_review_has_an_explicit_policy_boundary(): void
    {
        $context = $this->context();
        $candidate = $this->candidate($context);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);

        $this->assertTrue(Gate::forUser($registrar)->allows('reviewCandidates', $context['run']));
        $this->assertFalse(Gate::forUser($accounting)->allows('reviewCandidates', $context['run']));

        try {
            app(CandidateScheduleRowReviewService::class)->revise(
                $candidate,
                $this->revisionPayload($context),
                $accounting,
            );
            $this->fail('Accounting was allowed to correct a candidate schedule row.');
        } catch (AuthorizationException) {
            $this->assertSame('08:00:00', $candidate->fresh()->starts_at);
            $this->assertDatabaseCount('candidate_schedule_rows', 1);
        }
    }

    public function test_candidate_correction_validates_the_complete_set_and_records_override_evidence(): void
    {
        $context = $this->context();
        $candidate = $this->candidate($context);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $run = app(CandidateScheduleRowReviewService::class)->revise(
            $candidate,
            $this->revisionPayload($context),
            $registrar,
        );
        $corrected = $run->candidateRows()->sole();
        $diagnostics = $run->getAttribute('diagnostics');

        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
        $this->assertSame('09:00:00', $corrected->starts_at);
        $this->assertSame('12:00:00', $corrected->ends_at);
        $this->assertSame('Registrar scheduling memorandum', $corrected->override_authority);
        $this->assertSame('Corrected the assigned start time after faculty confirmation.', $corrected->override_reason);
        $this->assertSame([], $corrected->scores);
        $this->assertSame(CandidateScheduleRow::StatusOk, $corrected->status);
        $this->assertSame(ScheduleGenerationRun::StatusSuperseded, $context['run']->fresh()->status);
        $this->assertSame('08:00:00', $candidate->fresh()->starts_at);
        $this->assertSame('kept', $diagnostics['solver_result']['marker']);
        $this->assertSame('accepted', $diagnostics['current_revalidation']['status']);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ScheduleGenerationRun::class,
            'subject_id' => $run->id,
            'event' => 'candidate_correction',
            'causer_id' => $registrar->id,
        ]);
    }

    public function test_rejected_candidate_correction_preserves_rows_and_persists_structured_findings(): void
    {
        $context = $this->context();
        $candidate = $this->candidate($context);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        FacultyQualification::query()->whereKey($context['qualifications'][0]->id)->update(['is_active' => false]);

        try {
            app(CandidateScheduleRowReviewService::class)->revise(
                $candidate,
                $this->revisionPayload($context),
                $registrar,
            );
            $this->fail('A correction using a no-longer-qualified faculty member was saved.');
        } catch (ValidationException) {
            $preserved = CandidateScheduleRow::query()->sole();
            $run = $context['run']->fresh();
            $this->assertSame($candidate->id, $preserved->id);
            $this->assertSame('08:00:00', $preserved->starts_at);
            $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
            $this->assertArrayNotHasKey('current_revalidation', $run->diagnostics);
            $this->assertDatabaseMissing('activity_log', [
                'subject_type' => ScheduleGenerationRun::class,
                'subject_id' => $run->id,
                'event' => 'candidate_correction',
            ]);
        }
    }

    public function test_generic_manual_override_is_retired_and_writes_nothing(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $context = $this->context(runStatus: ScheduleGenerationRun::StatusBlocked);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Generic Manual Schedule Override is retired.');

        try {
            app(CandidateScheduleRowReviewService::class)->replace(
                $context['run'],
                [$this->assignment($context, 0)],
                $registrar,
                'Registrar override memo',
                'The obsolete whole-set path must remain unreachable.',
            );
        } finally {
            $this->assertSame(0, $context['run']->candidateRows()->count());
            $this->assertSame(ScheduleGenerationRun::StatusBlocked, $context['run']->fresh()->status);
        }
    }

    public function test_current_context_detects_authoritative_source_drift_across_constraint_families(): void
    {
        $qualification = $this->context();
        $qualification['qualifications'][0]->update(['is_active' => false]);
        $this->assertValidationCode($qualification, 'faculty_not_eligible');

        $room = $this->context();
        $room['room']->update(['capacity' => 5]);
        $this->assertValidationCode($room, 'room_not_suitable');

        $duration = $this->context();
        $duration['components'][0]->update([
            'weekly_contact_hours' => 2.00,
            'meeting_pattern' => '1x120',
        ]);
        $this->assertValidationCode($duration, 'assignment_duration_mismatch');

        $calendar = $this->context();
        $this->calendarBlock($calendar['term'], $calendar['faculty']);
        $this->assertValidationCode($calendar, 'calendar_block_overlap');

        $fixed = $this->context();
        $fixed['demands'][0]->update(['fixed_day_of_week' => 2]);
        $this->assertValidationCode($fixed, 'fixed_day_mismatch');

        $grid = $this->context();
        $grid['term']->update(['scheduling_day_starts_at' => '09:00:00']);
        $this->assertValidationCode($grid, 'assignment_outside_operating_grid');

        $load = $this->context();
        $load['term']->update(['default_max_units' => 2.00]);
        $this->assertValidationCode($load, 'faculty_load_exceeded');
    }

    public function test_publication_revalidates_current_sources_and_preserves_solver_diagnostics(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $blocked = $this->context();
        $this->candidate($blocked);
        $blocked['room']->update(['capacity' => 5]);

        try {
            app(SchedulePublishService::class)->publish($blocked['run'], $registrar);
            $this->fail('Publication ignored current room-capacity drift.');
        } catch (ValidationException) {
            $run = $blocked['run']->fresh();
            $diagnostics = $run->getAttribute('diagnostics');

            $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->status);
            $this->assertSame(0, SectionMeeting::query()->where('schedule_run_id', $run->id)->count());
            $this->assertSame('kept', $diagnostics['solver_result']['marker']);
            $this->assertSame('blocked', $diagnostics['current_revalidation']['status']);
        }

        $accepted = $this->context();
        $this->candidate($accepted);
        $published = app(SchedulePublishService::class)->publish($accepted['run'], $registrar);
        $diagnostics = $published->getAttribute('diagnostics');

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $published->status);
        $this->assertSame(1, $published->sectionMeetings()->count());
        $this->assertSame('kept', $diagnostics['solver_result']['marker']);
        $this->assertSame('accepted', $diagnostics['current_revalidation']['status']);
    }

    public function test_publication_uses_current_offering_modality_for_room_requirements_and_official_output(): void
    {
        $context = $this->context();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $context['offerings'][0]->update(['modality' => TermOffering::ModalityOnline]);
        $context['demands'][0]->sectionDeliveryGroup()->update(['modality' => TermOffering::ModalityOnline]);
        $candidate = $this->candidate($context);
        $candidate->update(['room_id' => null]);

        $published = app(SchedulePublishService::class)->publish($context['run'], $registrar);
        $meeting = $published->sectionMeetings()->sole();

        $this->assertSame(TermOffering::ModalityFaceToFace, $context['demands'][0]->modality);
        $this->assertSame(TermOffering::ModalityOnline, $meeting->modality);
        $this->assertNull($meeting->room_id);
    }

    public function test_live_assignment_entry_point_reports_unaffected_meeting_and_student_binding_conflicts(): void
    {
        $context = $this->context(demandCount: 2, runStatus: ScheduleGenerationRun::StatusPublished);
        $secondFaculty = $this->staff(User::StaffRoleFaculty);
        $secondRoom = Room::factory()->create([
            'code' => 'B2-R'.$this->contextCounter.'B',
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        FacultyQualification::factory()->for($secondFaculty, 'faculty')->for($context['courses'][1])->create([
            'is_active' => true,
        ]);
        $sourceMeeting = $this->officialMeeting(
            $context['run'],
            $context['demands'][0],
            $context['faculty'],
            $context['room'],
            '08:00:00',
            '11:00:00',
        );
        $unaffectedMeeting = $this->officialMeeting(
            $context['run'],
            $context['demands'][1],
            $secondFaculty,
            $secondRoom,
            '10:00:00',
            '13:00:00',
        );
        $student = StudentProfile::factory()->create(['program_id' => $context['programs'][0]->id]);
        $enrollment = Enrollment::factory()->for($student)->for($context['term'])->create([
            'credential_user_id' => $student->user_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
        ]);

        foreach ([$sourceMeeting, $unaffectedMeeting] as $index => $meeting) {
            $courseEnrollment = CourseEnrollment::query()->create([
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $context['offerings'][$index]->id,
                'section_id' => $meeting->schedulingDemand?->sectionDeliveryGroup?->section_id,
                'is_current' => true,
                'status' => CourseEnrollment::StatusActive,
                'units_snapshot' => '3.00',
                'added_at' => now(),
            ]);
            StudentScheduleBinding::query()->create([
                'course_enrollment_id' => $courseEnrollment->id,
                'section_meeting_id' => $meeting->id,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
                'source' => StudentScheduleBinding::SourceRegistrarPlacement,
            ]);
        }

        $validation = app(ScheduleAssignmentRevalidationService::class)->validateLiveAssignments(
            $context['run'],
            [
                [
                    ...$this->assignment($context, 0, startsAt: '10:00:00', endsAt: '13:00:00'),
                    'room_id' => $secondRoom->id,
                    'source_section_meeting_id' => $sourceMeeting->id,
                ],
                [
                    ...$this->assignment($context, 1, startsAt: '14:00:00', endsAt: '17:00:00'),
                    'faculty_user_id' => $secondFaculty->id,
                ],
            ],
            [$sourceMeeting->id],
        );
        $codes = collect($validation->findings())->pluck('code')->all();
        $roomFinding = collect($validation->findings())->firstWhere('code', 'live_room_overlap');

        $this->assertFalse($validation->passes());
        $this->assertContains('live_room_overlap', $codes);
        $this->assertContains('active_student_registration_overlap', $codes);
        $this->assertIsArray($roomFinding);
        $this->assertSame('room_id', $roomFinding['source_field']);
    }

    public function test_live_assignment_entry_point_rejects_shared_cohort_overlap_across_delivery_groups(): void
    {
        $context = $this->context(demandCount: 2, runStatus: ScheduleGenerationRun::StatusPublished);
        $firstEntry = $context['offerings'][0]->curriculumEntry;
        $secondEntry = $context['offerings'][1]->curriculumEntry;
        $firstGroup = $context['demands'][0]->sectionDeliveryGroup;
        $secondGroup = $context['demands'][1]->sectionDeliveryGroup;

        $secondEntry->curriculumVersion()->update([
            'program_id' => $firstEntry->curriculumVersion->program_id,
        ]);
        $secondGroup->update(['name' => $firstGroup->name]);

        $this->officialMeeting(
            $context['run'],
            $context['demands'][1],
            $context['faculty'],
            $context['room'],
            '10:00:00',
            '13:00:00',
        );

        $validation = app(ScheduleAssignmentRevalidationService::class)->validateLiveAssignments(
            $context['run'],
            [
                $this->assignment($context, 0, startsAt: '10:00:00', endsAt: '13:00:00'),
                $this->assignment($context, 1, startsAt: '14:00:00', endsAt: '17:00:00'),
            ],
        );

        $this->assertFalse($validation->passes());
        $this->assertContains(
            'live_cohort_overlap',
            collect($validation->findings())->pluck('code')->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(
        int $demandCount = 1,
        string $runStatus = ScheduleGenerationRun::StatusUnderReview,
    ): array {
        $this->contextCounter++;
        $term = Term::factory()->create([
            'label' => 'TAL-94B2 Term '.$this->contextCounter,
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
        ]);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $room = Room::factory()->create([
            'code' => 'B2-R'.$this->contextCounter,
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $programs = [];
        $courses = [];
        $components = [];
        $offerings = [];
        $demands = [];
        $qualifications = [];

        for ($index = 0; $index < $demandCount; $index++) {
            $suffix = $this->contextCounter.'-'.$index;
            $program = Program::factory()->create(['code' => 'B2'.$this->contextCounter.$index]);
            $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
            $course = Course::factory()->create(['code' => 'B2C'.$suffix]);
            $specification = CourseSpecification::factory()->for($course)->create([
                'state' => CourseSpecification::StateActive,
                'allowed_modalities' => [
                    TermOffering::ModalityFaceToFace,
                    TermOffering::ModalityOnline,
                ],
                'credit_units' => 3.00,
                'same_faculty_default' => false,
            ]);
            $component = CourseComponent::factory()->for($specification)->create([
                'component_type' => CourseComponent::TypeLecture,
                'weekly_contact_hours' => 3.00,
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
                'code' => 'B2-S'.$suffix,
                'capacity' => 30,
                'state' => Section::StateOpen,
            ]);
            $group = SectionDeliveryGroup::factory()->for($section)->create([
                'name' => 'TAL-94B2 Group '.$suffix,
                'expected_count' => 30,
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionDeliveryGroup::StateReady,
            ]);
            $qualification = FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create([
                'is_active' => true,
            ]);
            $demand = SchedulingDemand::factory()
                ->for($offering)
                ->for($component)
                ->for($group)
                ->create([
                    'required_duration_minutes' => 180,
                    'modality' => TermOffering::ModalityFaceToFace,
                    'validation_state' => SchedulingDemand::ValidationReadyForReview,
                ]);

            $programs[] = $program;
            $courses[] = $course;
            $components[] = $component;
            $offerings[] = $offering;
            $demands[] = $demand;
            $qualifications[] = $qualification;
        }

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $runStatus,
            'requested_by' => null,
            'input_snapshot' => [
                'scheduling_demands' => collect($demands)
                    ->map(fn (SchedulingDemand $demand): array => ['scheduling_demand_id' => $demand->id])
                    ->all(),
            ],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal94b2-test-solver',
            'diagnostics' => ['solver_result' => ['marker' => 'kept']],
            'published_at' => $runStatus === ScheduleGenerationRun::StatusPublished ? now() : null,
            'publication_version' => $runStatus === ScheduleGenerationRun::StatusPublished ? 1 : null,
        ]);

        return compact(
            'term',
            'faculty',
            'room',
            'programs',
            'courses',
            'components',
            'offerings',
            'demands',
            'qualifications',
            'run',
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function revisionPayload(array $context): array
    {
        return [
            'faculty_user_id' => $context['faculty']->id,
            'room_id' => $context['room']->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '12:00:00',
            'time_block_key' => 'D1-0900',
            'override_authority' => 'Registrar scheduling memorandum',
            'override_reason' => 'Corrected the assigned start time after faculty confirmation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function assignment(
        array $context,
        int $index,
        string $startsAt = '08:00:00',
        string $endsAt = '11:00:00',
    ): array {
        return [
            'scheduling_demand_id' => $context['demands'][$index]->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $context['faculty']->id,
            'room_id' => $context['room']->id,
            'day_of_week' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'time_block_key' => 'D1-'.str_replace(':', '', mb_substr($startsAt, 0, 5)),
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => [],
            'warnings' => [],
            'violations' => [],
        ];
    }

    private function candidate(array $context): CandidateScheduleRow
    {
        $assignment = $this->assignment($context, 0);

        return CandidateScheduleRow::query()->create([
            'schedule_run_id' => $context['run']->id,
            ...$assignment,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertValidationCode(array $context, string $expectedCode): void
    {
        $validation = app(ScheduleAssignmentRevalidationService::class)->validateCandidateSet(
            $context['run'],
            [$this->assignment($context, 0)],
        );

        $this->assertFalse($validation->passes());
        $this->assertContains($expectedCode, collect($validation->findings())->pluck('code')->all());
    }

    private function calendarBlock(Term $term, User $faculty): CalendarEvent
    {
        return CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeUnavailable,
            'scope_type' => CalendarEvent::ScopeFaculty,
            'faculty_user_id' => $faculty->id,
            'room_id' => null,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'start_at' => null,
            'end_at' => null,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
            'blocks_scheduling' => true,
            'state' => CalendarEvent::StateActive,
            'authority' => 'TAL-94B2 drift test',
        ]);
    }

    private function officialMeeting(
        ScheduleGenerationRun $run,
        SchedulingDemand $demand,
        User $faculty,
        Room $room,
        string $startsAt,
        string $endsAt,
    ): SectionMeeting {
        return SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
