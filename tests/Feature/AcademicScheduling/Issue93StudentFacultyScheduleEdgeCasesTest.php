<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\Scheduling\BuildOfficialScheduleOutput;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Filament\Pages\FacultyOverview;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\TermCalendar;
use App\Filament\Student\Pages\ScheduleView;
use App\Models\AcademicYear;
use App\Models\AdmissionCycle;
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
use App\Models\FacultyTermLoadOverride;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermDatedException;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Issue93StudentFacultyScheduleEdgeCasesTest extends TestCase
{
    use DatabaseTransactions;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());
    }

    #[Test]
    public function student_schedule_view_and_print_show_externally_arranged_classes_for_pure_ojt_enrollment(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar, 'OJT Registrar');
        $adviser = $this->staff(User::StaffRoleFaculty, 'Prof. Elena Practicum');
        [$student, $profile, $program] = $this->studentWithProfile();
        [$term, $curriculum] = $this->activeTermAndCurriculum($program, 'Summer 2026', '2026-06-01', '2026-07-31');

        $ojtCourse = Course::factory()->create(['code' => 'IT 499']);
        $ojtSpec = CourseSpecification::factory()->for($ojtCourse)->create([
            'title' => 'Practicum / On-the-Job Training',
            'state' => CourseSpecification::StateActive,
            'credit_units' => 6.00,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($ojtSpec, 'courseSpecification')->create([
            'term_type' => $term->type,
            'term_label' => $term->label,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'state' => TermOffering::StateScheduled,
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 25,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'BSIT-4A',
            'capacity' => 25,
            'state' => Section::StateOpen,
        ]);

        ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $adviser->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'OJT-MEMO-2026',
            'assigned_by' => $registrar->id,
            'effective_at' => now(),
        ]);

        $enrollment = $this->officialEnrollment($student, $profile, $term, $registrar);
        CourseEnrollment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($student)
            ->test(ScheduleView::class)
            ->assertSee('Externally arranged classes')
            ->assertSee('Practicum / On-the-Job Training')
            ->assertSee('IT 499')
            ->assertSee('BSIT-4A')
            ->assertSee($adviser->name)
            ->assertDontSee('Class meetings are not available yet');

        $output = app(BuildOfficialScheduleOutput::class)->forStudent($student, Request::create('/outputs/schedules/student'));
        $this->assertCount(1, $output['externally_arranged']);
        $this->assertSame('IT 499', $output['externally_arranged'][0]['course']);
        $this->assertSame($adviser->name, $output['externally_arranged'][0]['faculty']);

        $this->actingAs($student)
            ->get(route('student.schedule.print', ['term_id' => $term->id]))
            ->assertOk()
            ->assertSee('Externally arranged classes')
            ->assertSee('Practicum / On-the-Job Training')
            ->assertSee($adviser->name)
            ->assertDontSee('No schedule is available for this exact timetable version.');
    }

    #[Test]
    public function faculty_schedule_and_print_show_externally_arranged_classes_without_empty_state(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar, 'Faculty OJT Registrar');
        $adviser = $this->staff(User::StaffRoleFaculty, 'Prof. Marco Adviser');
        [$student, $profile, $program] = $this->studentWithProfile();
        [$term, $curriculum] = $this->activeTermAndCurriculum($program, 'Special Term 2026', '2026-06-05', '2026-07-25');

        $ojtCourse = Course::factory()->create(['code' => 'OJT 401']);
        $ojtSpec = CourseSpecification::factory()->for($ojtCourse)->create([
            'title' => 'Industry Immersion Practicum',
            'state' => CourseSpecification::StateActive,
            'credit_units' => 6.00,
            'scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($ojtSpec, 'courseSpecification')->create([
            'term_type' => $term->type,
            'term_label' => $term->label,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'state' => TermOffering::StateScheduled,
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 20,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'BSIT-4B',
            'capacity' => 20,
            'state' => Section::StateOpen,
        ]);

        ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $adviser->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'OJT-ASSIGN-2026',
            'assigned_by' => $registrar->id,
            'effective_at' => now(),
        ]);

        $enrollment = $this->officialEnrollment($student, $profile, $term, $registrar);
        CourseEnrollment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        Livewire::actingAs($adviser)
            ->test(FacultySchedule::class, ['termId' => $term->id])
            ->assertSee('Externally arranged classes')
            ->assertSee('Industry Immersion Practicum')
            ->assertSee('OJT 401')
            ->assertSee('BSIT-4B')
            ->assertSee('Designated instructor')
            ->assertDontSee('No official meetings assigned in this term');

        Livewire::actingAs($adviser)
            ->test(FacultyOverview::class, ['termId' => $term->id])
            ->assertSee('1 externally arranged class')
            ->assertDontSee('Not published yet');

        $output = app(BuildOfficialScheduleOutput::class)->forFaculty(
            $adviser,
            Request::create('/outputs/schedules/faculty', 'GET', ['term_id' => $term->id]),
        );
        $this->assertCount(1, $output['externally_arranged']);
        $this->assertSame('OJT 401', $output['externally_arranged'][0]['course']);
        $this->assertSame('Active', $output['version_state']);

        $this->actingAs($adviser)
            ->get(route('faculty.schedule.print', ['term_id' => $term->id]))
            ->assertOk()
            ->assertSee('Externally arranged classes')
            ->assertSee('Industry Immersion Practicum')
            ->assertDontSee('No schedule is available for this exact timetable version.');
    }

    #[Test]
    public function student_schedule_shows_conditional_term_switcher_when_enrolled_in_two_concurrent_active_terms(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar, 'Concurrent Term Registrar');
        $faculty = $this->staff(User::StaffRoleFaculty, 'Prof. Concurrent');
        [$student, $profile, $program] = $this->studentWithProfile();

        [$firstTerm, $firstCurriculum] = $this->activeTermAndCurriculum($program, '1st Semester Ongoing', '2026-08-01', '2026-12-15');
        $this->attachPublishedClass($student, $profile, $firstTerm, $firstCurriculum, $faculty, $registrar, 'CS 101', 'Ongoing Term Subject', 'BSIT-1A', 1, '08:00:00', '10:00:00');

        // With only 1 active Term, the Term switcher must NOT appear.
        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($student)
            ->test(ScheduleView::class)
            ->assertSee('Ongoing Term Subject')
            ->assertDontSee('Active term schedule');

        // Student early-enrolls in 2nd Semester while 1st Semester is still Active.
        [$secondTerm, $secondCurriculum] = $this->activeTermAndCurriculum($program, '2nd Semester Early', '2027-01-10', '2027-05-20');
        $this->attachPublishedClass($student, $profile, $secondTerm, $secondCurriculum, $faculty, $registrar, 'CS 102', 'Early Enrolled Subject', 'BSIT-1B', 3, '13:00:00', '15:00:00');

        // Resolver returns both concurrent active Terms, newest first.
        $allActive = app(CurrentOfficialEnrollmentResolver::class)->allActiveForStudent($student);
        $this->assertCount(2, $allActive);
        $this->assertSame($secondTerm->id, $allActive[0]->term_id);
        $this->assertSame($firstTerm->id, $allActive[1]->term_id);

        Livewire::actingAs($student)
            ->test(ScheduleView::class)
            ->assertSee('Active term schedule')
            ->assertSee('1st Semester Ongoing')
            ->assertSee('2nd Semester Early')
            ->assertSee('Early Enrolled Subject')
            ->call('selectTerm', $firstTerm->id)
            ->assertSet('termId', $firstTerm->id)
            ->assertSee('Ongoing Term Subject')
            ->assertDontSee('Early Enrolled Subject');

        // Printable schedule honors ?term_id= for the earlier ongoing active Term.
        $this->actingAs($student)
            ->get(route('student.schedule.print', ['term_id' => $firstTerm->id]))
            ->assertOk()
            ->assertSee('Ongoing Term Subject')
            ->assertDontSee('Early Enrolled Subject');

        // Closed term cannot be viewed via ?term_id= tampering.
        [$closedTerm] = $this->activeTermAndCurriculum($program, 'Closed Historical Term', '2025-08-01', '2025-12-15', Term::StateClosed);
        $this->actingAs($student)
            ->get(route('student.schedule.print', ['term_id' => $closedTerm->id]))
            ->assertNotFound();
    }

    #[Test]
    public function student_schedule_shows_student_number_hides_weekly_list_toggle_and_renders_academic_calendar_tab(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar, 'Calendar Tab Registrar');
        $faculty = $this->staff(User::StaffRoleFaculty, 'Prof. Calendar');
        [$student, $profile, $program] = $this->studentWithProfile();
        [$term, $curriculum] = $this->activeTermAndCurriculum($program, '1st Semester 2026', '2026-08-17', '2026-12-18');

        $this->attachPublishedClass($student, $profile, $term, $curriculum, $faculty, $registrar, 'CS 201', 'Algorithms', 'BSIT-2A', 1, '08:00:00', '10:00:00');

        $package = TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->firstOrFail();
        $package->update([
            'classes_start_on' => '2026-08-17',
            'classes_end_on' => '2026-12-18',
        ]);
        $package->teachingGridRows()->update([
            'breaks' => [
                ['starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ],
        ]);
        TermCalendarWindow::query()->create([
            'term_calendar_package_id' => $package->id,
            'window_type' => TermCalendarWindow::TypeExaminationPeriod,
            'opens_on' => '2026-12-14',
            'closes_on' => '2026-12-18',
        ]);
        TermDatedException::query()->create([
            'term_calendar_package_id' => $package->id,
            'label' => 'Founders Day Holiday',
            'exception_type' => 'Institutional Holiday',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-01',
            'blocks_teaching' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($student)
            ->test(ScheduleView::class)
            ->assertOk()
            ->assertSee($profile->student_number)
            ->assertSee('Your registered classes, meeting times, and term calendar.')
            ->assertSee('Class Schedule')
            ->assertSee('Academic Calendar')
            ->assertSee('Classes:')
            ->assertSee('Aug 17–Dec 18, 2026')
            ->assertSee('Exam period:')
            ->assertSee('Dec 14–Dec 18, 2026')
            ->assertDontSee('Schedule view')
            ->assertDontSee('Asia/Manila')
            ->call('setActiveTab', 'calendar')
            ->assertSet('activeTab', 'calendar')
            ->assertSee('Teaching period & weekly hours')
            ->assertSee('Weekly teaching hours')
            ->assertSee('12:00 PM–1:00 PM')
            ->assertSee('Academic windows')
            ->assertSee('Examination Period')
            ->assertSee('No-class holidays & dated exceptions')
            ->assertSee('Founders Day Holiday')
            ->assertDontSee('Asia/Manila');
    }

    #[Test]
    public function faculty_my_availability_shows_registrar_not_teaching_notice_and_blocks_editing_closed_terms(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar, 'Availability Guard Registrar');
        $faculty = $this->staff(User::StaffRoleFaculty, 'Prof. Sabbatical');
        [, , $program] = $this->studentWithProfile();
        [$activeTerm] = $this->activeTermAndCurriculum($program, 'Active Term Availability', '2026-08-01', '2026-12-15', Term::StateActive);
        $this->createActiveTeachingCalendar($activeTerm);

        FacultyTermLoadOverride::query()->create([
            'term_id' => $activeTerm->id,
            'faculty_user_id' => $faculty->id,
            'default_max_units_snapshot' => 0.00,
            'approved_overload_units' => 0.00,
            'authority' => 'Registrar Office',
            'reason' => 'Approved research sabbatical for First Semester.',
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $activeTerm->id])
            ->assertSee('Marked as not teaching this term by the Registrar')
            ->assertSee('Not teaching (Registrar)')
            ->assertSee('Approved research sabbatical for First Semester.');

        // Closed term must be read-only even if it still has an active calendar package.
        [$closedTerm] = $this->activeTermAndCurriculum($program, 'Closed Term Availability', '2025-08-01', '2025-12-15', Term::StateClosed);
        $this->createActiveTeachingCalendar($closedTerm);

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $closedTerm->id])
            ->assertSee('This term is closed')
            ->assertSee('Availability for a closed term is read-only and can no longer be edited.')
            ->assertDontSee('Save availability');

        Livewire::actingAs($faculty)
            ->test(MyAvailability::class, ['termId' => $closedTerm->id])
            ->set('availabilityMode', FacultyAvailabilityDeclaration::ModeAnyApproved)
            ->call('saveAvailability')
            ->assertStatus(409);

        try {
            app(RecordFacultyAvailabilityDeclaration::class)->execute(
                term: $closedTerm,
                faculty: $faculty,
                actor: $faculty,
                availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
            );

            $this->fail('Expected RecordFacultyAvailabilityDeclaration to reject a closed Term.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term', $exception->errors());
        }

        $this->assertSame(0, FacultyAvailabilityDeclaration::query()
            ->where('term_id', $closedTerm->id)
            ->where('faculty_user_id', $faculty->id)
            ->count());

        // FacultyOverview suppresses availability action nudges for Registrar Not-teaching and Closed terms.
        Livewire::actingAs($faculty)
            ->test(FacultyOverview::class, ['termId' => $activeTerm->id])
            ->assertSee('Not teaching (Registrar)')
            ->assertDontSee('No availability submitted for');

        Livewire::actingAs($faculty)
            ->test(FacultyOverview::class, ['termId' => $closedTerm->id])
            ->assertSee('This term is closed. Availability is read-only.')
            ->assertDontSee('No availability submitted for');

        // TermCalendar shows Admissions correction deadline read-only when set on linked AdmissionCycle.
        AdmissionCycle::factory()->create([
            'term_id' => $activeTerm->id,
            'state' => AdmissionCycle::StatePublished,
            'opens_at' => now()->subDays(2),
            'closes_at' => now()->addDays(10),
            'correction_closes_at' => now()->addDays(15),
        ]);

        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $activeTerm->id])
            ->assertSee('Corrections until');
    }

    private function staff(string $role, string $name): User
    {
        $this->seq++;
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name).'-'.$this->seq.'@servitech.edu.ph',
            'status' => User::StatusActive,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0:User,1:StudentProfile,2:Program} */
    private function studentWithProfile(): array
    {
        $this->seq++;
        Role::findOrCreate('student', 'web');
        $program = Program::factory()->create(['code' => 'BSIT'.$this->seq]);
        $student = User::factory()->create([
            'name' => 'Student Edge '.$this->seq,
            'email' => 'student-edge-'.$this->seq.'@servitech.edu.ph',
            'status' => User::StatusActive,
        ]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->for($student, 'user')->for($program)->create([
            'student_number' => '2026-99'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
        ]);

        return [$student, $profile, $program];
    }

    /** @return array{0:Term,1:CurriculumVersion} */
    private function activeTermAndCurriculum(
        Program $program,
        string $label,
        string $startsOn,
        string $endsOn,
        string $state = Term::StateActive,
    ): array {
        $this->seq++;
        $ay = AcademicYear::factory()->create([
            'label' => '2026-2027-'.$this->seq,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'state' => AcademicYear::StateActive,
        ]);
        $term = Term::factory()->for($ay)->create([
            'label' => $label,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'state' => $state,
            'default_max_units' => 24.00,
        ]);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateActive,
        ]);

        return [$term, $curriculum];
    }

    private function createActiveTeachingCalendar(Term $term): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
        ]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            TermTeachingGridRow::query()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'breaks' => [],
            ]);
        }

        return $package;
    }

    private function officialEnrollment(User $student, StudentProfile $profile, Term $term, User $registrar): Enrollment
    {
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'credential_user_id' => $student->id,
            'term_id' => $term->id,
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $cor = CorVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'content_hash' => hash('sha256', 'cor-'.$enrollment->id),
            'snapshot' => ['courses' => []],
            'issued_by' => $registrar->id,
            'issued_at' => now(),
        ]);
        $enrollment->forceFill(['current_cor_version_id' => $cor->id])->save();

        return $enrollment;
    }

    private function attachPublishedClass(
        User $student,
        StudentProfile $profile,
        Term $term,
        CurriculumVersion $curriculum,
        User $faculty,
        User $registrar,
        string $courseCode,
        string $courseTitle,
        string $sectionCode,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
    ): void {
        $this->createActiveTeachingCalendar($term);
        $room = Room::factory()->create([
            'code' => 'RM-'.$this->seq.'-'.Str::upper(Str::random(3)),
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 40,
            'is_active' => true,
        ]);
        $course = Course::factory()->create(['code' => $courseCode]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => $courseTitle,
            'state' => CourseSpecification::StateActive,
            'credit_units' => 3.00,
            'scheduling_treatment' => CourseSpecification::SchedulingRecurring,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        CourseComponent::factory()->for($spec)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 2.00,
            'room_type_default' => Room::TypeLectureRoom,
            'sequence' => 1,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'term_type' => $term->type,
            'term_label' => $term->label,
            'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'state' => TermOffering::StateScheduled,
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 30,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => $sectionCode,
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusPublished,
            'publication_version' => 1,
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);
        $version = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'EDGE-AUTH-'.$this->seq,
            'publication_reason' => 'Concurrent active term fixture.',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'tt-'.$term->id.'-'.$section->id),
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);
        PublishedTimetableMeeting::query()->create([
            'published_timetable_version_id' => $version->id,
            'section_id' => $section->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'location_label' => $room->name,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'modality' => TermOffering::ModalityFaceToFace,
        ]);

        $enrollment = $this->officialEnrollment($student, $profile, $term, $registrar);
        CourseEnrollment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'published_timetable_version_id' => $version->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);
    }
}
