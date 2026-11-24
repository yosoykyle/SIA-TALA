<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SubmitGradeRoster;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\ResolveTimetableRevisionRegistrationImpact;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\GradeRosters\Pages\ViewGradeRoster;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\PublishedScheduleRevisionForm;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\GradeRoster;
use App\Models\GradeRosterVersion;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
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
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\TimetableRevision;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Issue93MidSemesterReassignmentTest extends TestCase
{
    use DatabaseTransactions;

    private int $contextCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function temporary_substitute_reassignment_updates_timetable_and_optionally_adds_co_faculty_while_preserving_designated_grade_roster_owner(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $context = $this->context($registrar);
        $substituteFaculty = $this->qualifiedFaculty($context['course'], $context['term']);

        $changes = [[
            'section_meeting_id' => $context['meeting']->id,
            'faculty_user_id' => $substituteFaculty->id,
            'reassignment_scope' => PublishedScheduleRevisionForm::ScopeTemporarySubstitute,
            'add_co_faculty' => true,
        ]];

        try {
            app(PublishedScheduleRevisionService::class)->revise(
                run: $context['run'],
                actor: $registrar,
                changeType: ScheduleRevisionEvent::ChangeFacultyReassignment,
                changes: $changes,
                reason: 'Professor on two-week medical relief coverage.',
                authorityReference: 'MEMO-2026-RELIEF-01',
            );
        } catch (ValidationException $exception) {
            if (! array_key_exists('revision', $exception->errors())) {
                throw $exception;
            }

            $this->acknowledgeDraftRevisionImpact($context['run'], $registrar);

            app(PublishedScheduleRevisionService::class)->revise(
                run: $context['run'],
                actor: $registrar,
                changeType: ScheduleRevisionEvent::ChangeFacultyReassignment,
                changes: $changes,
                reason: 'Professor on two-week medical relief coverage.',
                authorityReference: 'MEMO-2026-RELIEF-01',
            );
        }

        $activeMeeting = SectionMeeting::query()
            ->activeOfficial()
            ->where('scheduling_demand_id', $context['meeting']->scheduling_demand_id)
            ->sole();

        $this->assertSame($substituteFaculty->id, $activeMeeting->faculty_user_id);
        $this->assertSame($context['faculty']->id, $context['roster']->fresh()->faculty_user_id);
        $this->assertSame($context['assignment']->id, $context['roster']->fresh()->teaching_assignment_id);
        $this->assertSame(ClassOfferingTeachingAssignment::StateActive, $context['assignment']->fresh()->state);

        $this->assertDatabaseHas('class_offering_teaching_assignments', [
            'section_id' => $context['section']->id,
            'faculty_user_id' => $substituteFaculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleCoFaculty,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'MEMO-2026-RELIEF-01',
        ]);
    }

    #[Test]
    public function permanent_takeover_reassignment_updates_timetable_and_transfers_designated_grade_roster_ownership(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $context = $this->context($registrar);
        $replacementFaculty = $this->qualifiedFaculty($context['course'], $context['term']);

        $context['roster']->rows()->update([
            'final_result' => '1.75',
            'inc_completion_note' => null,
        ]);
        app(SubmitGradeRoster::class)->execute($context['roster'], $context['faculty']);
        $this->assertSame(GradeRoster::StateSubmitted, $context['roster']->fresh()->state);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $publishedVersion = PublishedTimetableVersion::query()->create([
            'term_id' => $context['term']->id,
            'schedule_run_id' => $context['run']->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'MEMO-2026-INIT',
            'publication_reason' => 'Initial published version for reassignment test.',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'midsem-'.$context['run']->id),
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
        ]);
        $context['meeting']->forceFill(['published_timetable_version_id' => $publishedVersion->id])->save();
        PublishedTimetableMeeting::factory()->create([
            'published_timetable_version_id' => $publishedVersion->id,
            'section_id' => $context['section']->id,
            'scheduling_demand_id' => $context['meeting']->scheduling_demand_id,
            'faculty_user_id' => $context['faculty']->id,
            'room_id' => $context['meeting']->room_id,
            'meeting_sequence' => $context['meeting']->meeting_sequence,
            'day_of_week' => $context['meeting']->day_of_week,
            'starts_at' => $context['meeting']->starts_at,
            'ends_at' => $context['meeting']->ends_at,
        ]);

        $payload = [
            'section_meeting_ids' => [$context['meeting']->id],
            'change_type' => ScheduleRevisionEvent::ChangeFacultyReassignment,
            'replacement_faculty_user_id' => $replacementFaculty->id,
            'reassignment_scope' => PublishedScheduleRevisionForm::ScopePermanentTakeover,
            'reason' => 'Permanent mid-semester faculty takeover approved by Dean.',
        ];

        $firstAttempt = Livewire::actingAs($registrar)
            ->withQueryParams(['termId' => $context['term']->id, 'timetableState' => 'official'])
            ->test(TermTimetable::class)
            ->callAction('revisePublishedSchedule', data: $payload);

        $pendingRevision = TimetableRevision::query()
            ->where('state', TimetableRevision::StateDraft)
            ->whereHas('sourceVersion', fn ($query) => $query->where('schedule_run_id', $context['run']->id))
            ->latest('id')
            ->first();

        if ($pendingRevision instanceof TimetableRevision) {
            $this->acknowledgeDraftRevisionImpact($context['run'], $registrar);

            Livewire::actingAs($registrar)
                ->withQueryParams(['termId' => $context['term']->id, 'timetableState' => 'official'])
                ->test(TermTimetable::class)
                ->callAction('revisePublishedSchedule', data: $payload)
                ->assertHasNoActionErrors();
        } else {
            $firstAttempt->assertHasNoActionErrors();
        }

        $activeMeeting = SectionMeeting::query()
            ->activeOfficial()
            ->where('scheduling_demand_id', $context['meeting']->scheduling_demand_id)
            ->sole();

        $this->assertSame($replacementFaculty->id, $activeMeeting->faculty_user_id);

        $freshRoster = $context['roster']->fresh();
        $this->assertSame($replacementFaculty->id, $freshRoster->faculty_user_id);
        $this->assertSame(GradeRoster::StateDraft, $freshRoster->state);
        $this->assertSame(ClassOfferingTeachingAssignment::StateReplaced, $context['assignment']->fresh()->state);
        $this->assertSame(GradeRosterVersion::StateInvalidated, $freshRoster->versions()->sole()->state);

        $this->assertDatabaseHas('class_offering_teaching_assignments', [
            'id' => $freshRoster->teaching_assignment_id,
            'section_id' => $context['section']->id,
            'faculty_user_id' => $replacementFaculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'Permanent mid-semester faculty takeover approved by Dean.',
        ]);
    }

    #[Test]
    public function view_grade_roster_exposes_reassign_faculty_header_action_for_registrar_until_released(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $context = $this->context($registrar);
        $replacementFaculty = $this->staff(User::StaffRoleFaculty);
        $coFaculty = $this->staff(User::StaffRoleFaculty);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($academicHead)
            ->test(ViewGradeRoster::class, ['record' => $context['roster']->id])
            ->assertActionHidden('reassignFaculty');

        Livewire::actingAs($registrar)
            ->test(ViewGradeRoster::class, ['record' => $context['roster']->id])
            ->assertActionVisible('reassignFaculty')
            ->callAction('reassignFaculty', data: [
                'faculty_user_id' => $replacementFaculty->id,
                'co_faculty_user_id' => $coFaculty->id,
                'add_co_faculty' => true,
                'authority_reference' => 'MEMO-2026-ROSTER-93',
                'reason' => 'Original instructor unavailable for final grade encoding.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Faculty reassigned');

        $freshRoster = $context['roster']->fresh();
        $this->assertSame($replacementFaculty->id, $freshRoster->faculty_user_id);
        $this->assertSame(ClassOfferingTeachingAssignment::StateReplaced, $context['assignment']->fresh()->state);

        $this->assertDatabaseHas('class_offering_teaching_assignments', [
            'section_id' => $context['section']->id,
            'faculty_user_id' => $replacementFaculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'MEMO-2026-ROSTER-93',
        ]);
        $this->assertDatabaseHas('class_offering_teaching_assignments', [
            'section_id' => $context['section']->id,
            'faculty_user_id' => $coFaculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleCoFaculty,
            'state' => ClassOfferingTeachingAssignment::StateActive,
        ]);
        $this->assertDatabaseHas('class_offering_teaching_assignments', [
            'section_id' => $context['section']->id,
            'faculty_user_id' => $context['faculty']->id,
            'role' => ClassOfferingTeachingAssignment::RoleCoFaculty,
            'state' => ClassOfferingTeachingAssignment::StateActive,
        ]);

        $auditLog = DB::table('activity_log')
            ->where('event', 'grade_roster_faculty_reassigned')
            ->where('subject_type', GradeRoster::class)
            ->where('subject_id', $freshRoster->id)
            ->where('causer_id', $registrar->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertStringContainsString('Original instructor unavailable for final grade encoding.', (string) $auditLog->properties);

        $freshRoster->update([
            'state' => GradeRoster::StateReleased,
            'released_at' => now(),
            'released_by' => $registrar->id,
        ]);

        Livewire::actingAs($registrar)
            ->test(ViewGradeRoster::class, ['record' => $freshRoster->id])
            ->assertActionHidden('reassignFaculty');
    }

    #[Test]
    public function light_mode_foundation_tokens_use_projector_friendly_surface_contrast_while_preserving_dark_mode(): void
    {
        $foundation = (string) file_get_contents(public_path('css/tala-foundation.css'));

        $this->assertStringContainsString('--tala-canvas: #f4f4f5;', $foundation);
        $this->assertStringContainsString('--tala-surface: #ffffff;', $foundation);
        $this->assertStringContainsString('--tala-surface-raised: #ffffff;', $foundation);
        $this->assertStringContainsString('--tala-edge: rgb(9 9 11 / 12%);', $foundation);
        $this->assertStringContainsString('--tala-edge-strong: rgb(9 9 11 / 20%);', $foundation);

        $this->assertStringContainsString('--tala-canvas: #151619;', $foundation);
        $this->assertStringContainsString('--tala-surface: #27282c;', $foundation);
        $this->assertStringContainsString('--tala-surface-raised: #303237;', $foundation);
    }

    /**
     * @return array{
     *     term: Term,
     *     faculty: User,
     *     course: Course,
     *     section: Section,
     *     run: ScheduleGenerationRun,
     *     meeting: SectionMeeting,
     *     assignment: ClassOfferingTeachingAssignment,
     *     roster: GradeRoster
     * }
     */
    private function context(User $registrar): array
    {
        $this->contextCounter++;
        $term = Term::factory()->create([
            'label' => 'Issue 93 Term '.$this->contextCounter,
            'state' => Term::StateActive,
            'default_max_units' => 21.00,
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeGradeEntry,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDay()->toDateString(),
            'cutoff_at' => '23:59:59',
        ]);
        foreach ([1, 2, 3, 4, 5, 6] as $day) {
            $package->teachingGridRows()->create([
                'day_of_week' => $day,
                'starts_at' => '07:00:00',
                'ends_at' => '21:00:00',
                'breaks' => [],
            ]);
        }

        $program = Program::factory()->create(['code' => 'I93P'.$this->contextCounter]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'I93C'.$this->contextCounter]);
        $specification = CourseSpecification::factory()->for($course)->create([
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace, TermOffering::ModalityOnline],
            'credit_units' => 3.00,
            'same_faculty_default' => true,
        ]);
        $component = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'meeting_pattern' => '1x180',
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
            'code' => 'I93-S'.$this->contextCounter,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'Issue 93 Group '.$this->contextCounter,
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);

        $faculty = $this->qualifiedFaculty($course, $term);
        $room = Room::factory()->create([
            'code' => 'I93-R'.$this->contextCounter,
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create([
                'required_duration_minutes' => 180,
                'meeting_count' => 1,
                'modality' => TermOffering::ModalityFaceToFace,
                'validation_state' => SchedulingDemand::ValidationReadyForReview,
            ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => [
                'scheduling_demands' => [['scheduling_demand_id' => $demand->id]],
            ],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'issue93-test-solver',
            'published_at' => now()->subDay(),
            'publication_version' => 1,
        ]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now()->subDay(),
        ]);

        $student = StudentProfile::factory()->create(['program_id' => $program->id]);
        $enrollment = Enrollment::factory()->for($student)->for($term)->create([
            'credential_user_id' => $student->user_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);

        $assignment = app(ManageTeachingAssignment::class)->designate($section, $faculty, $registrar, 'I93-INITIAL-ASSIGN');
        $roster = app(SynchronizeOfficialGradeRoster::class)->execute($section, $registrar);

        return compact('term', 'faculty', 'course', 'section', 'run', 'meeting', 'assignment', 'roster');
    }

    private function qualifiedFaculty(Course $course, Term $term): User
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create(['is_active' => true]);
        FacultyAvailabilityDeclaration::factory()
            ->for($term)
            ->for($faculty, 'faculty')
            ->create([
                'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
                'availability_mode' => FacultyAvailabilityDeclaration::ModeAnyApproved,
                'hard_unavailability' => [],
            ]);

        return $faculty;
    }

    private function acknowledgeDraftRevisionImpact(ScheduleGenerationRun $run, User $registrar): void
    {
        $revision = TimetableRevision::query()
            ->where('state', TimetableRevision::StateDraft)
            ->whereHas('sourceVersion', fn ($query) => $query->where('schedule_run_id', $run->id))
            ->latest('id')
            ->firstOrFail();
        $sourceReference = 'timetable-revision:'.$revision->id;

        foreach ($revision->impact_snapshot['affected_registration_case_ids'] ?? [] as $caseId) {
            $case = Enrollment::query()->findOrFail($caseId);
            $opened = RegistrationCaseEvent::query()
                ->where('enrollment_id', $case->id)
                ->where('event_type', 'TimetableRevisionImpactReviewOpened')
                ->where('authority_reference', $sourceReference)
                ->sole();
            app(ResolveTimetableRevisionRegistrationImpact::class)->execute(
                $revision,
                $case,
                $opened,
                $registrar,
                ResolveTimetableRevisionRegistrationImpact::OutcomeRetainedWithAcknowledgement,
                'I93-ACK-'.$case->id,
            );
        }
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
