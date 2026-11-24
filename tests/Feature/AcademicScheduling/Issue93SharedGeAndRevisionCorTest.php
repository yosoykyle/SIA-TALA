<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Cor\BuildCorOutput;
use App\Actions\Enrollment\IssueRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\PrepareStandardRegistration;
use App\Actions\Enrollment\StudentUnitLoadService;
use App\Actions\Finance\CreateFeePlanDraft;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Scheduling\BuildOfficialScheduleOutput;
use App\Actions\Scheduling\PublishedScheduleRevisionService;
use App\Actions\Scheduling\RevisePublishedTimetable;
use App\Mail\ScheduleRevisionMail;
use App\Models\CorVersion;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCaseEvent;
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
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Issue93SharedGeAndRevisionCorTest extends TestCase
{
    use DatabaseTransactions;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

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
    public function shared_ge_class_created_for_bsit_and_bshm_can_be_enrolled_by_bshm_student_in_clinic_4(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $bsit = Program::factory()->create(['code' => 'BSIT-93', 'name' => 'BS Information Technology']);
        $bshm = Program::factory()->create(['code' => 'BSHM-93', 'name' => 'BS Hospitality Management']);

        $bsitCurriculum = CurriculumVersion::factory()->for($bsit)->create(['state' => CurriculumVersion::StateActive]);
        $bshmCurriculum = CurriculumVersion::factory()->for($bshm)->create(['state' => CurriculumVersion::StateActive]);

        $term = $this->activeTermWithEnrollmentWindow(Term::TypeFirstSemester);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $this->publishedFeePlanFor($bshm, $term, $accounting);

        $geSpec = $this->activeRecurringSpecification('GE101-93', 'Understanding the Self', 3.00);
        $hmSpec = $this->activeRecurringSpecification('HM101-93', 'Macro Perspective of Tourism', 3.00);
        $equivGeSpec = $this->activeRecurringSpecification('GE101-EQ-93', 'Self & Society Equivalent', 3.00);
        CourseRequirement::query()->create([
            'course_specification_id' => $geSpec->id,
            'rule_type' => CourseRequirement::TypeEquivalency,
            'group_key' => 'EQUIV-GE101',
            'related_course_id' => $equivGeSpec->course_id,
            'state' => CourseRequirement::StateActive,
            'sequence' => 1,
        ]);

        // Host GE offering lives under BSIT Year 2, while BSHM places GE101 in Year 1.
        $bsitGeEntry = CurriculumEntry::factory()->for($bsitCurriculum)->for($geSpec, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);
        CurriculumEntry::factory()->for($bshmCurriculum)->for($geSpec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 1,
        ]);
        $bshmMajorEntry = CurriculumEntry::factory()->for($bshmCurriculum)->for($hmSpec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 2,
        ]);

        $bsitCohort = TermCohort::factory()->for($term)->for($bsitCurriculum)->create([
            'program_id' => $bsit->id,
            'reference' => 'BSIT-2A',
            'state' => 'Confirmed',
            'confirmed_count' => 20,
        ]);
        $bshmCohort = TermCohort::factory()->for($term)->for($bshmCurriculum)->create([
            'program_id' => $bshm->id,
            'reference' => 'BSHM-1A',
            'state' => 'Confirmed',
            'confirmed_count' => 20,
        ]);

        $sharedGeOffering = TermOffering::factory()->for($term)->for($bsitGeEntry, 'curriculumEntry')->create([
            'category' => TermOffering::CategoryRegular,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $sharedGeSection = Section::factory()->for($sharedGeOffering, 'termOffering')->create([
            'course_specification_id' => $geSpec->id,
            'code' => 'GE101-SHARED-A',
            'source' => Section::SourceShared,
            'capacity' => 40,
            'state' => Section::StateOpen,
        ]);
        $sharedGeSection->cohorts()->attach([
            $bsitCohort->id => ['expected_count' => 20],
            $bshmCohort->id => ['expected_count' => 20],
        ]);

        $bshmMajorOffering = TermOffering::factory()->for($term)->for($bshmMajorEntry, 'curriculumEntry')->create([
            'category' => TermOffering::CategoryRegular,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $bshmMajorSection = Section::factory()->for($bshmMajorOffering, 'termOffering')->create([
            'course_specification_id' => $hmSpec->id,
            'code' => 'HM101-1A',
            'source' => Section::SourceRegular,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $bshmMajorSection->cohorts()->attach([
            $bshmCohort->id => ['expected_count' => 20],
        ]);

        $timetable = PublishedTimetableVersion::factory()->for($term)->create([
            'state' => PublishedTimetableVersion::StatePublished,
            'published_by' => $registrar->id,
        ]);
        $roomA = Room::factory()->create(['is_active' => true]);
        $roomB = Room::factory()->create(['is_active' => true]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $sharedGeSection->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $roomA->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => $roomA->name,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $bshmMajorSection->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $roomB->id,
            'day_of_week' => 2,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => $roomB->name,
        ]);

        $bshmStudentUser = User::factory()->create(['status' => User::StatusActive]);
        $bshmStudentUser->assignRole('student');
        $bshmProfile = StudentProfile::factory()->create([
            'user_id' => $bshmStudentUser->id,
            'program_id' => $bshm->id,
            'curriculum_version_id' => $bshmCurriculum->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);

        $standardCase = Enrollment::factory()->for($bshmProfile)->for($term)->create([
            'credential_user_id' => $bshmStudentUser->id,
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'lock_version' => 1,
        ]);

        $derivedIds = app(PrepareStandardRegistration::class)->sectionIds($standardCase);
        $this->assertEqualsCanonicalizing([$sharedGeSection->id, $bshmMajorSection->id], $derivedIds);

        $preparedStandard = app(PrepareStandardRegistration::class)->execute($standardCase, $registrar);
        $standardProposal = $preparedStandard->currentProposalVersion;
        $this->assertNotNull($standardProposal);
        $this->assertSame(RegistrationProposalVersion::StateIssued, $standardProposal->state, (string) $preparedStandard->status_reason);
        $this->assertSame($bshmCurriculum->id, $standardProposal->curriculum_version_id);
        $this->assertSame('First Year', data_get($standardProposal->source_snapshot, 'unit_load.year_level'));
        $this->assertSame('6.00', data_get($standardProposal->source_snapshot, 'unit_load.normal_total'));
        $this->assertSame('6.00', data_get($standardProposal->source_snapshot, 'unit_load.requested_total'));

        // Equivalent course hosted on BSIT curriculum also enrolls cleanly under BSHM curriculum.
        $bsitEquivEntry = CurriculumEntry::factory()->for($bsitCurriculum)->for($equivGeSpec, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => 2,
        ]);
        $equivOffering = TermOffering::factory()->for($term)->for($bsitEquivEntry, 'curriculumEntry')->create([
            'category' => TermOffering::CategoryRegular,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
        ]);
        $equivSection = Section::factory()->for($equivOffering, 'termOffering')->create([
            'course_specification_id' => $equivGeSpec->id,
            'code' => 'GE101EQ-SHARED',
            'source' => Section::SourceShared,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
            'section_id' => $equivSection->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $roomA->id,
            'day_of_week' => 3,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => $roomA->name,
        ]);

        // Duplicate subject in one proposal is still rejected.
        $advisedUser = User::factory()->create(['status' => User::StatusActive]);
        $advisedUser->assignRole('student');
        $advisedProfile = StudentProfile::factory()->create([
            'user_id' => $advisedUser->id,
            'program_id' => $bshm->id,
            'curriculum_version_id' => $bshmCurriculum->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);
        $advisedCase = Enrollment::factory()->for($advisedProfile)->for($term)->create([
            'credential_user_id' => $advisedUser->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'lock_version' => 1,
        ]);

        try {
            app(PrepareRegistrationProposal::class)->execute(
                $advisedCase,
                $registrar,
                [$sharedGeSection->id, $equivSection->id],
                1,
            );
            $this->fail('Duplicate canonical/equivalent courses in a single proposal must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sections', $exception->errors());
        }

        // Single equivalent GE section resolves to BSHM Year 1 First Semester (6.00 ceiling, 3.00 requested).
        $equivProposal = app(PrepareRegistrationProposal::class)->execute(
            $advisedCase,
            $registrar,
            [$equivSection->id, $bshmMajorSection->id],
            1,
        );
        $this->assertSame($bshmCurriculum->id, $equivProposal->curriculum_version_id);
        $this->assertSame('6.00', data_get($equivProposal->source_snapshot, 'unit_load.normal_total'));
        $this->assertSame('6.00', data_get($equivProposal->source_snapshot, 'unit_load.requested_total'));
        $this->assertTrue(data_get($equivProposal->source_snapshot, 'unit_load.unit_load_passes'));
    }

    #[Test]
    public function revising_a_published_meeting_time_detects_an_enrolled_students_real_schedule_conflict(): void
    {
        $fixture = $this->twoSectionPublishedScheduleFixture();

        // Attempt to move Section A (Mon 08:00-11:00) to Mon 13:00-16:00, colliding with Section B.
        $changes = [[
            'section_meeting_id' => $fixture['meetingA']->id,
            'day_of_week' => 1,
            'starts_at' => '13:00:00',
            'ends_at' => '16:00:00',
        ]];

        try {
            app(PublishedScheduleRevisionService::class)->revise(
                $fixture['run'],
                $fixture['registrar'],
                ScheduleRevisionEvent::ChangeTime,
                $changes,
                'Move Section A to afternoon slot.',
            );
            $this->fail('Revising Section A into Section B slot must be blocked by student schedule conflict.');
        } catch (ValidationException $exception) {
            $message = implode(' ', collect($exception->errors())->flatten()->all());
            $this->assertStringContainsString('active meeting for an affected student', $message);
        }

        // Direct RevisePublishedTimetable / TimetableRevisionImpactGuard path must also detect the student conflict.
        try {
            app(RevisePublishedTimetable::class)->execute(
                $fixture['timetableVersion'],
                $fixture['registrar'],
                [$fixture['publishedMeetingA']->id => ['day_of_week' => 1, 'starts_at' => '13:00:00', 'ends_at' => '16:00:00']],
                'SIGNOFF-CONFLICT-CHECK',
                'Direct call conflict check.',
            );
            $this->fail('Direct RevisePublishedTimetable must detect student schedule conflict via source_section_meeting_id.');
        } catch (ValidationException $exception) {
            $message = implode(' ', collect($exception->errors())->flatten()->all());
            $this->assertStringContainsString('active meeting for an affected student', $message);
        }

        $this->assertSame(PublishedTimetableVersion::StatePublished, $fixture['timetableVersion']->fresh()->state);
        $this->assertSame(0, ScheduleRevisionEvent::query()->count());
        $this->assertSame(1, CorVersion::query()->where('enrollment_id', $fixture['enrollment']->id)->count());
    }

    #[Test]
    public function revising_a_published_meeting_room_and_non_conflicting_time_succeeds_without_deadlock_and_bumps_cor(): void
    {
        Mail::fake();

        $fixture = $this->twoSectionPublishedScheduleFixture();

        // 1) Room-only revision succeeds immediately without opening blocking review cases.
        $roomEvents = app(PublishedScheduleRevisionService::class)->revise(
            $fixture['run'],
            $fixture['registrar'],
            ScheduleRevisionEvent::ChangeRoom,
            [[
                'section_meeting_id' => $fixture['meetingA']->id,
                'room_id' => $fixture['replacementRoom']->id,
            ]],
            'Room maintenance relocation.',
        );

        $this->assertCount(1, $roomEvents);
        $this->assertSame(0, RegistrationCaseEvent::query()
            ->where('enrollment_id', $fixture['enrollment']->id)
            ->where('event_type', 'TimetableRevisionImpactReviewOpened')
            ->count());

        $corV2 = $fixture['enrollment']->fresh()->currentCorVersion;
        $this->assertNotNull($corV2);
        $this->assertSame(2, $corV2->version);
        $this->assertSame($fixture['corV1']->id, $corV2->supersedes_version_id);

        $corOutputAfterRoom = app(BuildCorOutput::class)->forStudent($fixture['student']);
        $subjectAAfterRoom = collect($corOutputAfterRoom['subjects'])->firstWhere('subject_code', $fixture['courseA']->code);
        $this->assertSame($fixture['replacementRoom']->name, $subjectAAfterRoom['room']);

        // Queued ScheduleRevisionMail operational event carries the bumped cor_version_id and cor_url.
        $emailEvent = OperationalEvent::query()
            ->where('user_id', $fixture['student']->id)
            ->where('event_type', OperationalEvent::TypeScheduleRevisionEmail)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($corV2->id, data_get($emailEvent->payload, 'affected_enrollments.0.cor_version_id'));
        $this->assertNotNull(data_get($emailEvent->payload, 'affected_enrollments.0.cor_url'));
        Mail::assertQueued(ScheduleRevisionMail::class);

        // 2) Non-conflicting time revision (Tue 09:00-12:00, while Section B is Mon 13:00-16:00) also succeeds without deadlock and bumps COR to v3.
        $currentMeetingA = SectionMeeting::query()->findOrFail($roomEvents->sole()->section_meeting_id);
        $currentRun = ScheduleGenerationRun::query()->findOrFail($currentMeetingA->schedule_run_id);

        $timeEvents = app(PublishedScheduleRevisionService::class)->revise(
            $currentRun,
            $fixture['registrar'],
            ScheduleRevisionEvent::ChangeTime,
            [[
                'section_meeting_id' => $currentMeetingA->id,
                'day_of_week' => 2,
                'starts_at' => '09:00:00',
                'ends_at' => '12:00:00',
            ]],
            'Non-conflicting Tuesday morning adjustment.',
        );

        $this->assertCount(1, $timeEvents);
        $corV3 = $fixture['enrollment']->fresh()->currentCorVersion;
        $this->assertNotNull($corV3);
        $this->assertSame(3, $corV3->version);
        $this->assertSame($corV2->id, $corV3->supersedes_version_id);

        $corOutputAfterTime = app(BuildCorOutput::class)->forStudent($fixture['student']);
        $subjectAAfterTime = collect($corOutputAfterTime['subjects'])->firstWhere('subject_code', $fixture['courseA']->code);
        $this->assertSame('Tuesday', $subjectAAfterTime['day']);
        $this->assertSame('09:00 - 12:00', $subjectAAfterTime['time']);
        $this->assertSame($fixture['replacementRoom']->name, $subjectAAfterTime['room']);

        $liveSchedule = app(BuildOfficialScheduleOutput::class)->forStudent($fixture['student'], Request::create('/student/schedule'));
        $liveSubjectA = collect($liveSchedule['rows'])->firstWhere('course', $fixture['courseA']->code);
        $this->assertSame('Tuesday', $liveSubjectA['day']);
        $this->assertSame('9:00 AM - 12:00 PM', $liveSubjectA['time']);
    }

    #[Test]
    public function special_term_retake_of_regular_semester_subject_enforces_nine_unit_summer_ceiling(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        $program = Program::factory()->create(['code' => 'BSIT-ST93']);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);

        // Curriculum has 21 units in 1st Year, 1st Semester and NO explicit Special Term entries.
        $specialTerm = $this->activeTermWithEnrollmentWindow(Term::TypeSpecialTerm);
        $specialTerm->update(['default_max_units' => 24.00]);
        $timetable = PublishedTimetableVersion::factory()->for($specialTerm)->create([
            'state' => PublishedTimetableVersion::StatePublished,
            'published_by' => $registrar->id,
        ]);

        $sections = collect();
        foreach (range(1, 7) as $index) {
            $spec = $this->activeRecurringSpecification('ST93-'.$index, 'Regular Subject '.$index, 3.00);
            $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
                'year_level' => 'First Year',
                'term_label' => 'First Semester',
                'term_type' => Term::TypeFirstSemester,
                'sequence' => $index,
            ]);

            if ($index <= 4) {
                $offering = TermOffering::factory()->for($specialTerm)->for($entry, 'curriculumEntry')->create([
                    'category' => TermOffering::CategorySpecial,
                    'special_reason' => 'Retake offering',
                    'modality' => TermOffering::ModalityFaceToFace,
                    'state' => TermOffering::StateScheduled,
                ]);
                $section = Section::factory()->for($offering, 'termOffering')->create([
                    'course_specification_id' => $spec->id,
                    'code' => 'ST93-S'.$index,
                    'source' => Section::SourceAdditional,
                    'authority_reference' => 'RET-93-'.$index,
                    'capacity' => 30,
                    'state' => Section::StateOpen,
                ]);
                PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
                    'section_id' => $section->id,
                    'faculty_user_id' => $faculty->id,
                    'day_of_week' => $index,
                    'starts_at' => '08:00:00',
                    'ends_at' => '11:00:00',
                    'modality' => TermOffering::ModalityFaceToFace,
                ]);
                $sections->push($section);
            }
        }

        $studentUser = User::factory()->create(['status' => User::StatusActive]);
        $studentUser->assignRole('student');
        $profile = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
            'academic_standing' => StudentProfile::StandingRegular,
        ]);
        $enrollment = Enrollment::factory()->for($profile)->for($specialTerm)->create([
            'credential_user_id' => $studentUser->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'lock_version' => 1,
        ]);

        // 3 retaken regular-semester subjects (9.00 units) pass under the 9-unit SummerRetakeUnitCeiling.
        $unitLoadService = app(StudentUnitLoadService::class);
        $withinCapSnapshot = $unitLoadService->snapshotForSections($enrollment, $sections->take(3));
        $this->assertSame('9.00', $withinCapSnapshot['normal_total']);
        $this->assertSame('9.00', $withinCapSnapshot['requested_total']);
        $this->assertFalse($withinCapSnapshot['requires_graduating_overload']);
        $this->assertTrue($withinCapSnapshot['unit_load_passes']);

        // 4 retaken regular-semester subjects (12.00 units) must NOT inherit the 21-unit First Semester ceiling.
        $overCapSnapshot = $unitLoadService->snapshotForSections($enrollment, $sections->take(4));
        $this->assertSame('9.00', $overCapSnapshot['normal_total']);
        $this->assertSame('12.00', $overCapSnapshot['requested_total']);
        $this->assertTrue($overCapSnapshot['requires_graduating_overload']);
        $this->assertFalse($overCapSnapshot['unit_load_passes']);

        $overCapProposal = app(PrepareRegistrationProposal::class)->execute(
            $enrollment,
            $registrar,
            $sections->take(4)->pluck('id')->all(),
            1,
        );

        try {
            app(IssueRegistrationProposal::class)->execute($overCapProposal, $registrar);
            $this->fail('Special Term retake exceeding 9.00 units must require graduating-overload authority.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('graduating-overload authority', $exception->getMessage());
        }
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

    private function publishedFeePlanFor(Program $program, Term $term, User $accounting): void
    {
        $charges = [
            ['code' => 'TUITION-93', 'label' => 'Tuition Fee', 'amount' => '3000.00'],
        ];
        $obligations = [
            [
                'code' => 'TUITION-93',
                'label' => 'Tuition Fee',
                'purpose' => 'Enrollment',
                'amount' => '3000.00',
                'due_at' => now()->subMinute()->toDateTimeString(),
                'required_for_enrollment' => true,
            ],
        ];
        $plan = app(CreateFeePlanDraft::class)->execute($program, $term, $charges, $accounting, obligations: $obligations);
        app(PublishFeePlan::class)->execute(
            $plan,
            $accounting,
            'FEE-93-AUTH',
            CarbonImmutable::parse('2026-08-01', config('app.timezone')),
            'Published fee plan for Clinic 4 shared GE regression test.',
        );
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
