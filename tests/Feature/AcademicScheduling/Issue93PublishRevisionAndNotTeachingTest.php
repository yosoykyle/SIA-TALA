<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\FacultyAvailabilityStatusProjection;
use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Actions\Scheduling\TimetableRevisionImpactGuard;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\TermResources;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\PublishedScheduleRevisionForm;
use App\Models\CandidateScheduleRow;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CorVersion;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
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
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

final class Issue93PublishRevisionAndNotTeachingTest extends TestCase
{
    use DatabaseTransactions;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function timetable_revision_cor_reissue_preserves_pending_course_drop_and_meeting_identity(): void
    {
        Mail::fake();

        $fixture = $this->twoSectionPublishedScheduleFixture();
        $snapshot = $fixture['corV1']->snapshot;
        $snapshot['change'] = [
            'type' => 'CourseDrop',
            'record_id' => 9001,
            'finance_state' => 'AccountingReviewPending',
        ];
        DB::table($fixture['corV1']->getTable())
            ->where('id', $fixture['corV1']->id)
            ->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);

        app(PublishedScheduleRevisionService::class)->revise(
            $fixture['run'],
            $fixture['registrar'],
            ScheduleRevisionEvent::ChangeRoom,
            [[
                'section_meeting_id' => $fixture['meetingA']->id,
                'room_id' => $fixture['replacementRoom']->id,
            ]],
            'Room maintenance relocation.',
        );

        $reissued = $fixture['enrollment']->fresh()->currentCorVersion;
        $this->assertSame(2, $reissued->version);
        $this->assertSame('CourseDrop', data_get($reissued->snapshot, 'change.type'));
        $this->assertSame('AccountingReviewPending', data_get($reissued->snapshot, 'change.finance_state'));
        $this->assertSame('TimetableRevision', data_get($reissued->snapshot, 'timetable_revision.type'));

        $course = collect($reissued->snapshot['courses'])->firstWhere('section_id', $fixture['sectionA']->id);
        $meeting = $course['meetings'][0];
        $this->assertArrayHasKey('id', $meeting);
        $this->assertSame(1, $meeting['meeting_sequence']);
        $this->assertSame($fixture['replacementRoom']->name, $meeting['room_label']);
    }

    #[Test]
    public function initial_publication_designates_the_published_faculty_for_each_section(): void
    {
        $registrar = $this->registrarWithMfa();
        $faculty = $this->staff(User::StaffRoleFaculty);
        [$run, $section] = $this->candidateRunFor($faculty);

        app(SchedulePublishService::class)->publish($run, $registrar, 'Reviewed for publication.', 'AUTH-93-INITIAL');

        $assignment = ClassOfferingTeachingAssignment::query()
            ->where('section_id', $section->id)
            ->where('role', ClassOfferingTeachingAssignment::RoleDesignated)
            ->where('state', ClassOfferingTeachingAssignment::StateActive)
            ->sole();

        $this->assertSame($faculty->id, $assignment->faculty_user_id);
        $this->assertSame($section->term_offering_id, $assignment->term_offering_id);
        $this->assertSame($registrar->id, $assignment->assigned_by);
        $this->assertSame('AUTH-93-INITIAL', $assignment->authority_reference);
    }

    #[Test]
    public function initial_publication_keeps_an_existing_active_designation(): void
    {
        $registrar = $this->registrarWithMfa();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $designated = $this->staff(User::StaffRoleFaculty);
        [$run, $section] = $this->candidateRunFor($faculty);
        ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $section->term_offering_id,
            'section_id' => $section->id,
            'faculty_user_id' => $designated->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'PRE-EXISTING',
            'assigned_by' => $registrar->id,
            'effective_at' => now(),
        ]);

        app(SchedulePublishService::class)->publish($run, $registrar, 'Reviewed for publication.');

        $assignment = ClassOfferingTeachingAssignment::query()->where('section_id', $section->id)->sole();
        $this->assertSame($designated->id, $assignment->faculty_user_id);
        $this->assertSame('PRE-EXISTING', $assignment->authority_reference);
    }

    #[Test]
    public function publishing_a_pending_draft_honors_permanent_takeover_scope(): void
    {
        Mail::fake();

        $fixture = $this->twoSectionPublishedScheduleFixture();
        FacultyQualification::factory()->for($fixture['facultyB'], 'faculty')->for($fixture['courseA'])->create(['is_active' => true]);
        ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $fixture['sectionA']->term_offering_id,
            'section_id' => $fixture['sectionA']->id,
            'faculty_user_id' => $fixture['facultyA']->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'ORIGINAL',
            'assigned_by' => $fixture['registrar']->id,
            'effective_at' => now(),
        ]);

        $draft = app(TimetableRevisionImpactGuard::class)->prepare(
            $fixture['timetableVersion'],
            $fixture['registrar'],
            [$fixture['publishedMeetingA']->id => [
                'faculty_user_id' => $fixture['facultyB']->id,
                'room_id' => $fixture['roomA']->id,
                'day_of_week' => 1,
                'starts_at' => '08:00:00',
                'ends_at' => '11:00:00',
                'modality' => TermOffering::ModalityFaceToFace,
                'location_label' => (string) $fixture['roomA']->id,
                'remove' => false,
                'reassignment_scope' => PublishedScheduleRevisionForm::ScopePermanentTakeover,
                'add_co_faculty' => false,
            ]],
            'AUTH-93-TAKEOVER',
            'Permanent takeover after resignation.',
        );

        app(PublishedScheduleRevisionService::class)->publishDraftRevision($fixture['run'], $draft, $fixture['registrar']);

        $designated = ClassOfferingTeachingAssignment::query()
            ->where('section_id', $fixture['sectionA']->id)
            ->where('role', ClassOfferingTeachingAssignment::RoleDesignated)
            ->where('state', ClassOfferingTeachingAssignment::StateActive)
            ->sole();
        $this->assertSame($fixture['facultyB']->id, $designated->faculty_user_id);
    }

    #[Test]
    public function marking_not_teaching_is_blocked_while_published_classes_remain_and_restore_refreshes_demand(): void
    {
        $fixture = $this->twoSectionPublishedScheduleFixture();
        $this->actingAsRegistrarForPlanning($fixture['registrar']);
        $spare = $this->staff(User::StaffRoleFaculty);
        FacultyQualification::factory()->for($spare, 'faculty')->for($fixture['courseA'])->create(['is_active' => true]);
        $reviewRun = ScheduleGenerationRun::factory()->create([
            'term_id' => $fixture['term']->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => 'Draft',
            'published_by' => null,
            'published_at' => null,
        ]);

        $component = Livewire::withQueryParams(['termId' => $fixture['term']->id])->test(TermResources::class);

        $component->callTableAction('markNotTeaching', 'faculty-'.$fixture['facultyA']->id, data: ['reason' => 'Study leave'])
            ->assertHasNoTableActionErrors();
        $this->assertFalse(FacultyTermLoadOverride::query()
            ->where('term_id', $fixture['term']->id)
            ->where('faculty_user_id', $fixture['facultyA']->id)
            ->exists());
        $this->assertSame('Draft', $reviewRun->fresh()->candidate_state);

        $component->callTableAction('markNotTeaching', 'faculty-'.$spare->id, data: ['reason' => 'Study leave'])
            ->assertHasNoTableActionErrors();
        $this->assertTrue(FacultyTermLoadOverride::query()
            ->where('term_id', $fixture['term']->id)
            ->where('faculty_user_id', $spare->id)
            ->where('is_active', true)
            ->exists());
        $this->assertSame('Stale', $reviewRun->fresh()->candidate_state);

        $reviewRun->forceFill(['candidate_state' => 'Draft'])->save();
        $component->callTableAction('restoreTeaching', 'faculty-'.$spare->id)
            ->assertHasNoTableActionErrors();
        $this->assertFalse(FacultyTermLoadOverride::query()
            ->where('term_id', $fixture['term']->id)
            ->where('faculty_user_id', $spare->id)
            ->where('is_active', true)
            ->exists());
        $this->assertSame('Stale', $reviewRun->fresh()->candidate_state);
    }

    #[Test]
    public function registrar_not_teaching_status_is_consistent_and_not_counted_as_no_response(): void
    {
        $fixture = $this->twoSectionPublishedScheduleFixture();
        $excluded = $this->staff(User::StaffRoleFaculty);
        $unreplied = $this->staff(User::StaffRoleFaculty);
        foreach ([$excluded, $unreplied] as $teacher) {
            FacultyQualification::factory()->for($teacher, 'faculty')->for($fixture['courseA'])->create(['is_active' => true]);
        }
        FacultyAvailabilityDeclaration::factory()->create([
            'term_id' => $fixture['term']->id,
            'faculty_user_id' => $excluded->id,
            'version' => 1,
            'declaration' => FacultyAvailabilityDeclaration::DeclarationUnavailable,
            'availability_mode' => FacultyAvailabilityDeclaration::ModeCannotTeach,
            'hard_unavailability' => [],
        ]);
        $this->notTeachingOverride($fixture['term'], $excluded);

        $projection = app(FacultyAvailabilityStatusProjection::class);
        $termRows = $projection->forTerm($fixture['term']);
        $facultyStatus = $projection->forFaculty($fixture['term'], $excluded);

        $this->assertSame('Not teaching (Registrar)', $termRows['faculty_rows'][$excluded->id]['status_label']);
        $this->assertSame('Not teaching (Registrar)', $facultyStatus['status_label']);
        $this->assertSame('No response', $termRows['faculty_rows'][$unreplied->id]['status_label']);
        $this->assertSame(1, $termRows['no_response_count']);
        $this->assertSame(1, $termRows['cannot_teach_count']);
    }

    #[Test]
    public function my_availability_is_read_only_when_registrar_marked_faculty_not_teaching(): void
    {
        $fixture = $this->twoSectionPublishedScheduleFixture();
        $spare = $this->staff(User::StaffRoleFaculty);
        $this->notTeachingOverride($fixture['term'], $spare);

        $component = Livewire::actingAs($spare)
            ->test(MyAvailability::class, ['termId' => $fixture['term']->id])
            ->assertSee('Not teaching (Registrar)')
            ->assertDontSee('Save availability')
            ->assertDontSee('Not submitted yet');

        $component->set('availabilityMode', FacultyAvailabilityDeclaration::ModeAnyApproved);

        try {
            $component->call('saveAvailability');
        } catch (Throwable) {
            // The 409 guard may surface as an HTTP exception; only the persisted result matters.
        }

        $this->assertFalse(FacultyAvailabilityDeclaration::query()
            ->where('term_id', $fixture['term']->id)
            ->where('faculty_user_id', $spare->id)
            ->exists());
    }

    private function notTeachingOverride(Term $term, User $faculty): FacultyTermLoadOverride
    {
        return FacultyTermLoadOverride::query()->create([
            'faculty_user_id' => $faculty->id,
            'term_id' => $term->id,
            'default_max_units_snapshot' => 0,
            'approved_overload_units' => 0,
            'authority' => 'Registrar Term Resources (test)',
            'reason' => 'On sabbatical leave',
            'is_active' => true,
        ]);
    }

    private function actingAsRegistrarForPlanning(User $registrar): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->syncPermissions([
            Permission::findOrCreate('manage-schedules', 'web'),
            Permission::findOrCreate('approve-documents', 'web'),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($registrar)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);
    }

    private function registrarWithMfa(): User
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $registrar->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $registrar->saveAppAuthenticationRecoveryCodes(['stored-code']);

        return $registrar;
    }

    /**
     * @return array{0: ScheduleGenerationRun, 1: Section}
     */
    private function candidateRunFor(User $faculty): array
    {
        $term = Term::factory()->create();
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive]);
        foreach ([1, 2, 3, 4, 5, 6] as $day) {
            $package->teachingGridRows()->create([
                'day_of_week' => $day,
                'starts_at' => '07:00:00',
                'ends_at' => '21:00:00',
                'breaks' => [],
            ]);
        }

        $room = Room::factory()->create(['room_type' => Room::TypeLectureRoom, 'capacity' => 100, 'is_active' => true]);
        $offering = TermOffering::factory()->for($term)->create(['modality' => TermOffering::ModalityFaceToFace]);
        $specification = CourseSpecification::factory()->create();
        $component = CourseComponent::factory()->for($specification)->create([
            'weekly_contact_hours' => 2.00,
            'meeting_pattern' => '1x120',
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $group = SectionDeliveryGroup::factory()->for($section)->create(['modality' => TermOffering::ModalityFaceToFace]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($specification->course)->create();
        $demand = SchedulingDemand::factory()->for($offering)->for($component)->for($group)->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'meeting_count' => 1,
            'required_duration_minutes' => 120,
        ]);

        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'requested_by' => null,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'issue93-test-solver',
        ]);
        CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'time_block_key' => 'D1-0800',
            'status' => CandidateScheduleRow::StatusOk,
            'scores' => [],
            'warnings' => [],
            'violations' => [],
        ]);

        return [$run, $section];
    }

    /**
     * @return array<string, mixed>
     */
    private function twoSectionPublishedScheduleFixture(): array
    {
        $this->counter++;
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $facultyA = $this->staff(User::StaffRoleFaculty);
        $facultyB = $this->staff(User::StaffRoleFaculty);

        $term = $this->activeTermWithEnrollmentWindow(Term::TypeFirstSemester);
        $term->update(['default_max_units' => 21.00]);
        $package = TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->firstOrFail();
        foreach ([1, 2, 3, 4, 5, 6] as $day) {
            $package->teachingGridRows()->create([
                'day_of_week' => $day,
                'starts_at' => '07:00:00',
                'ends_at' => '21:00:00',
                'breaks' => [],
            ]);
        }

        $roomA = Room::factory()->create(['code' => '93R-A'.$this->counter, 'name' => 'Room A '.$this->counter, 'room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);
        $roomB = Room::factory()->create(['code' => '93R-B'.$this->counter, 'name' => 'Room B '.$this->counter, 'room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);
        $replacementRoom = Room::factory()->create(['code' => '93R-C'.$this->counter, 'name' => 'Room C '.$this->counter, 'room_type' => Room::TypeLectureRoom, 'capacity' => 40, 'is_active' => true]);

        $program = Program::factory()->create(['code' => 'P93-'.$this->counter]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);

        $specA = $this->activeRecurringSpecification('C93A-'.$this->counter, 'Course A '.$this->counter, 3.00);
        $specB = $this->activeRecurringSpecification('C93B-'.$this->counter, 'Course B '.$this->counter, 3.00);
        $courseA = $specA->course;
        $courseB = $specB->course;

        $entryA = CurriculumEntry::factory()->for($curriculum)->for($specA, 'courseSpecification')->create(['term_type' => $term->type, 'term_label' => $term->label, 'sequence' => 1]);
        $entryB = CurriculumEntry::factory()->for($curriculum)->for($specB, 'courseSpecification')->create(['term_type' => $term->type, 'term_label' => $term->label, 'sequence' => 2]);

        $cohortA = TermCohort::factory()->for($term)->for($curriculum)->create(['program_id' => $program->id, 'state' => 'Confirmed', 'confirmed_count' => 30]);
        $cohortB = TermCohort::factory()->for($term)->for($curriculum)->create(['program_id' => $program->id, 'state' => 'Confirmed', 'confirmed_count' => 30]);

        $offeringA = TermOffering::factory()->for($term)->for($entryA, 'curriculumEntry')->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StateScheduled, 'expected_count' => 30]);
        $offeringB = TermOffering::factory()->for($term)->for($entryB, 'curriculumEntry')->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StateScheduled, 'expected_count' => 30]);

        $sectionA = Section::factory()->for($offeringA, 'termOffering')->create(['term_calendar_package_id' => $package->id, 'course_specification_id' => $specA->id, 'code' => 'S93A-'.$this->counter, 'capacity' => 30, 'state' => Section::StateOpen, 'confirmed_at' => now()]);
        $sectionB = Section::factory()->for($offeringB, 'termOffering')->create(['term_calendar_package_id' => $package->id, 'course_specification_id' => $specB->id, 'code' => 'S93B-'.$this->counter, 'capacity' => 30, 'state' => Section::StateOpen, 'confirmed_at' => now()]);
        $sectionA->cohorts()->attach($cohortA->id, ['expected_count' => 30]);
        $sectionB->cohorts()->attach($cohortB->id, ['expected_count' => 30]);

        $groupA = SectionDeliveryGroup::factory()->for($sectionA)->create(['expected_count' => 30, 'modality' => TermOffering::ModalityFaceToFace, 'state' => SectionDeliveryGroup::StateReady]);
        $groupB = SectionDeliveryGroup::factory()->for($sectionB)->create(['expected_count' => 30, 'modality' => TermOffering::ModalityFaceToFace, 'state' => SectionDeliveryGroup::StateReady]);

        FacultyQualification::factory()->for($facultyA, 'faculty')->for($courseA)->create(['is_active' => true]);
        FacultyQualification::factory()->for($facultyB, 'faculty')->for($courseB)->create(['is_active' => true]);
        foreach ([$facultyA, $facultyB] as $teacher) {
            FacultyAvailabilityDeclaration::factory()->create([
                'term_id' => $term->id,
                'faculty_user_id' => $teacher->id,
                'version' => 1,
                'declaration' => FacultyAvailabilityDeclaration::DeclarationAvailable,
                'availability_mode' => FacultyAvailabilityDeclaration::ModeAnyApproved,
                'hard_unavailability' => [],
            ]);
        }

        $demandA = SchedulingDemand::factory()->for($offeringA)->for($specA->components->first())->for($groupA)->create([
            'required_duration_minutes' => 180,
            'meeting_count' => 1,
            'modality' => TermOffering::ModalityFaceToFace,
            'validation_state' => SchedulingDemand::ValidationReadyForReview,
        ]);
        $demandB = SchedulingDemand::factory()->for($offeringB)->for($specB->components->first())->for($groupB)->create([
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
                'scheduling_demands' => [
                    ['scheduling_demand_id' => $demandA->id],
                    ['scheduling_demand_id' => $demandB->id],
                ],
            ],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'solver_version' => ScheduleGenerationRun::SolverVersion,
            'model_version' => 'cp-sat-v2',
            'quality_policy' => ScheduleGenerationRun::QualityPolicyLexicographic,
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
            'publication_version' => 1,
        ]);

        $meetingA = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demandA->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $facultyA->id,
            'room_id' => $roomA->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now()->subDay(),
        ]);
        $meetingB = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demandB->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $facultyB->id,
            'room_id' => $roomB->id,
            'day_of_week' => 1,
            'starts_at' => '13:00:00',
            'ends_at' => '16:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now()->subDay(),
        ]);

        $timetableVersion = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => '93-AUTH-'.$this->counter,
            'publication_reason' => 'Initial published timetable.',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'v1-'.$this->counter),
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
        ]);

        $publishedMeetingA = PublishedTimetableMeeting::query()->create([
            'published_timetable_version_id' => $timetableVersion->id,
            'section_id' => $sectionA->id,
            'scheduling_demand_id' => $demandA->id,
            'faculty_user_id' => $facultyA->id,
            'room_id' => $roomA->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => $roomA->name,
        ]);
        PublishedTimetableMeeting::query()->create([
            'published_timetable_version_id' => $timetableVersion->id,
            'section_id' => $sectionB->id,
            'scheduling_demand_id' => $demandB->id,
            'faculty_user_id' => $facultyB->id,
            'room_id' => $roomB->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '13:00:00',
            'ends_at' => '16:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => $roomB->name,
        ]);

        $meetingA->update(['published_timetable_version_id' => $timetableVersion->id]);
        $meetingB->update(['published_timetable_version_id' => $timetableVersion->id]);

        $student = User::factory()->create(['status' => User::StatusActive, 'email' => 'student93-'.$this->counter.'@example.test']);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'credential_user_id' => $student->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'registered_at' => now()->subDay(),
            'officially_enrolled_at' => now(),
            'lock_version' => 1,
        ]);
        $courseEnrollmentA = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offeringA->id,
            'section_id' => $sectionA->id,
            'published_timetable_version_id' => $timetableVersion->id,
            'is_current' => true,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        $courseEnrollmentB = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offeringB->id,
            'section_id' => $sectionB->id,
            'published_timetable_version_id' => $timetableVersion->id,
            'is_current' => true,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);

        $corV1 = CorVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'published_timetable_version_id' => $timetableVersion->id,
            'issued_by' => $registrar->id,
            'snapshot' => [
                'student_number' => $profile->student_number,
                'student_name' => $student->name,
                'program_id' => $program->id,
                'program_code' => $program->code,
                'curriculum_version_id' => $curriculum->id,
                'term_label' => $term->label,
                'published_timetable_version_id' => $timetableVersion->id,
                'assessment_total' => '0.00',
                'fees' => [],
                'courses' => [
                    [
                        'course_enrollment_id' => $courseEnrollmentA->id,
                        'section_id' => $sectionA->id,
                        'section_code' => $sectionA->code,
                        'course_code' => $courseA->code,
                        'course_title' => $specA->title,
                        'units' => '3.00',
                        'meetings' => [[
                            'day_of_week' => 1,
                            'starts_at' => '08:00:00',
                            'ends_at' => '11:00:00',
                            'room_label' => $roomA->name,
                            'faculty_name' => $facultyA->name,
                            'modality' => TermOffering::ModalityFaceToFace,
                        ]],
                    ],
                    [
                        'course_enrollment_id' => $courseEnrollmentB->id,
                        'section_id' => $sectionB->id,
                        'section_code' => $sectionB->code,
                        'course_code' => $courseB->code,
                        'course_title' => $specB->title,
                        'units' => '3.00',
                        'meetings' => [[
                            'day_of_week' => 1,
                            'starts_at' => '13:00:00',
                            'ends_at' => '16:00:00',
                            'room_label' => $roomB->name,
                            'faculty_name' => $facultyB->name,
                            'modality' => TermOffering::ModalityFaceToFace,
                        ]],
                    ],
                ],
            ],
        ]);
        $enrollment->update(['current_cor_version_id' => $corV1->id]);

        return compact(
            'registrar',
            'student',
            'term',
            'run',
            'timetableVersion',
            'meetingA',
            'publishedMeetingA',
            'roomA',
            'replacementRoom',
            'courseA',
            'enrollment',
            'corV1',
            'facultyA',
            'facultyB',
            'sectionA',
            'roomB',
        );
    }

    private function activeTermWithEnrollmentWindow(string $type): Term
    {
        $term = Term::factory()->create([
            'type' => $type,
            'state' => Term::StateActive,
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addMonth()->toDateString(),
            'cutoff_at' => '23:59:59',
        ]);

        return $term;
    }

    private function activeRecurringSpecification(string $code, string $title, float $units): CourseSpecification
    {
        $course = Course::factory()->create(['code' => $code]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => $title,
            'state' => CourseSpecification::StateActive,
            'credit_units' => $units,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => $units,
            'meeting_pattern' => '1x180',
            'room_type_default' => Room::TypeLectureRoom,
            'required_room_feature_keys' => [],
            'requires_consecutive_block' => false,
            'sequence' => 1,
        ]);

        return $spec->load('course', 'components');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
