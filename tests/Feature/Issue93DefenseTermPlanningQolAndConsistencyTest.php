<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\FacultyAvailabilityStatusProjection;
use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Actions\Scheduling\ReadyTermPlanningProjection;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Actions\Scheduling\TermCapacityHeadroomProjection;
use App\Filament\Pages\SchoolResources;
use App\Filament\Pages\TermResources;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\PublishedScheduleRevisionForm;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\Program;
use App\Models\PublishedTimetableMeeting;
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
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93DefenseTermPlanningQolAndConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_term_resources_bulk_action_sets_any_approved_for_no_response_and_skips_replied_or_excluded(): void
    {
        $registrar = $this->actingAsRegistrar();
        [$term, $package, , $curriculum] = $this->activeTermContext();

        $noResponseFaculty = $this->makeFaculty('Ana', 'Lim');
        $alreadyRepliedFaculty = $this->makeFaculty('Ben', 'Cruz');
        $excludedFaculty = $this->makeFaculty('Carla', 'Reyes');

        [$course, $spec] = $this->createCourseWithComponent('IT 101', 'Intro Computing', 3.0, 3.0, '2x90');
        $entry = CurriculumEntry::factory()
            ->for($curriculum, 'curriculumVersion')
            ->for($spec, 'courseSpecification')
            ->create(['year_level' => 'First Year', 'term_type' => $term->type]);
        $offering = TermOffering::factory()
            ->for($term)
            ->for($entry, 'curriculumEntry')
            ->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StatePendingScheduling]);
        $this->createConfirmedSection($offering, $package, $spec, 'IT101-1A');

        foreach ([$noResponseFaculty, $alreadyRepliedFaculty, $excludedFaculty] as $faculty) {
            FacultyQualification::factory()->create([
                'faculty_user_id' => $faculty->id,
                'course_id' => $course->id,
                'is_active' => true,
            ]);
        }

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $alreadyRepliedFaculty,
            actor: $alreadyRepliedFaculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00']],
        );

        FacultyTermLoadOverride::factory()->create([
            'term_id' => $term->id,
            'faculty_user_id' => $excludedFaculty->id,
            'default_max_units_snapshot' => 0,
            'approved_overload_units' => 0,
            'reason' => 'Study leave',
            'is_active' => true,
        ]);

        $draftRun = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'candidate_state' => 'UnderReview',
        ]);

        Livewire::withQueryParams(['termId' => $term->id])
            ->test(TermResources::class)
            ->assertTableBulkActionExists('setAnyApprovedAvailability')
            ->callTableBulkAction(
                'setAnyApprovedAvailability',
                [
                    'faculty-'.$noResponseFaculty->id,
                    'faculty-'.$alreadyRepliedFaculty->id,
                    'faculty-'.$excludedFaculty->id,
                ],
                data: ['correction_reason' => 'Confirmed available during approved school hours by Registrar'],
            )
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Availability updated for 1 teacher');

        $anaDeclaration = FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->where('faculty_user_id', $noResponseFaculty->id)
            ->latest('version')
            ->first();

        $this->assertInstanceOf(FacultyAvailabilityDeclaration::class, $anaDeclaration);
        $this->assertSame(FacultyAvailabilityDeclaration::ModeAnyApproved, $anaDeclaration->effectiveMode());
        $this->assertSame('Confirmed available during approved school hours by Registrar', $anaDeclaration->correction_reason);

        $this->assertSame(
            1,
            FacultyAvailabilityDeclaration::query()
                ->where('term_id', $term->id)
                ->where('faculty_user_id', $alreadyRepliedFaculty->id)
                ->count(),
        );
        $this->assertSame(
            0,
            FacultyAvailabilityDeclaration::query()
                ->where('term_id', $term->id)
                ->where('faculty_user_id', $excludedFaculty->id)
                ->count(),
        );
        $this->assertSame('Stale', $draftRun->fresh()->candidate_state);
    }

    public function test_official_load_falls_back_to_term_offering_specification_and_default_max_units_aligns_across_surfaces(): void
    {
        $registrar = $this->actingAsRegistrar();
        [$term, $package, , $curriculum] = $this->activeTermContext();
        $term->update(['default_max_units' => null]);

        $faculty = $this->makeFaculty('Marco', 'Dela Cruz');
        [$course, $spec] = $this->createCourseWithComponent('CS 201', 'Data Structures', 3.0, 3.0, '2x90');
        $entry = CurriculumEntry::factory()
            ->for($curriculum, 'curriculumVersion')
            ->for($spec, 'courseSpecification')
            ->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        $offering = TermOffering::factory()
            ->for($term)
            ->for($entry, 'curriculumEntry')
            ->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StatePendingScheduling]);

        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'CS201-2A',
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => null,
            'capacity' => 30,
            'state' => Section::StatePlanned,
            'confirmed_at' => now(),
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => 'CS201-2A',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        app(GenerateSchedulingDemand::class)->forTerm($registrar, $term);
        $demand = SchedulingDemand::query()->where('term_offering_id', $offering->id)->sole();

        FacultyQualification::factory()->create([
            'faculty_user_id' => $faculty->id,
            'course_id' => $course->id,
            'is_active' => true,
        ]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );

        $room = Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 40]);
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'candidate_state' => 'Accepted',
            'published_by' => $registrar->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $version = PublishedTimetableVersion::factory()->for($term)->create([
            'schedule_run_id' => $run->id,
            'state' => PublishedTimetableVersion::StatePublished,
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $version->id,
            'scheduling_demand_id' => $demand->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);
        PublishedTimetableMeeting::query()->create([
            'published_timetable_version_id' => $version->id,
            'section_id' => $section->id,
            'scheduling_demand_id' => $demand->id,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => $room->code,
        ]);
        ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => ClassOfferingTeachingAssignment::TimetableAuthorityPrefix.$version->version,
            'assigned_by' => $registrar->id,
            'effective_at' => now(),
        ]);

        $availabilityProjection = app(FacultyAvailabilityStatusProjection::class)->forTerm($term);
        $facultyRow = $availabilityProjection['faculty_rows'][$faculty->id];
        $headroom = app(TermCapacityHeadroomProjection::class)->forTerm($term);
        $revisionSummary = PublishedScheduleRevisionForm::facultyLoadSummary($run->fresh(), [$meeting->id], $faculty->id);

        $this->assertEqualsWithDelta(3.0, $facultyRow['load_units'], 0.01);
        $this->assertEqualsWithDelta(24.0, $facultyRow['load_limit'], 0.01);
        $this->assertEqualsWithDelta(24.0, $headroom['faculty_units']['available_units'], 0.01);
        $this->assertNotNull($revisionSummary);
        $this->assertEqualsWithDelta(24.0, $revisionSummary['limit'], 0.01);
    }

    public function test_capacity_headroom_excludes_online_and_uses_face_to_face_group_capacity(): void
    {
        $this->actingAsRegistrar();
        [$term, $package, $program, $curriculum] = $this->activeTermContext();
        Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 40]);

        $cohort = TermCohort::factory()->for($term)->for($program)->for($curriculum, 'curriculumVersion')->create([
            'reference' => 'BSIT-1A',
            'year_level' => 1,
            'confirmed_count' => 55,
            'confirmed_at' => now(),
        ]);

        [$onlineCourse, $onlineSpec] = $this->createCourseWithComponent('GE 101', 'Online Seminar', 3.0, 3.0, '2x90');
        $onlineSpec->update(['allowed_modalities' => [TermOffering::ModalityOnline]]);
        $onlineEntry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($onlineSpec, 'courseSpecification')->create(['year_level' => 'First Year', 'term_type' => $term->type]);
        $onlineOffering = TermOffering::factory()->for($term)->for($onlineEntry, 'curriculumEntry')->create(['modality' => TermOffering::ModalityOnline, 'state' => TermOffering::StatePendingScheduling]);
        $onlineSection = $this->createConfirmedSection($onlineOffering, $package, $onlineSpec, 'GE101-OL');
        SectionDeliveryGroup::factory()->for($onlineSection)->create([
            'name' => 'GE101-OL',
            'expected_count' => 55,
            'modality' => TermOffering::ModalityOnline,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        $onlineSection->cohorts()->attach($cohort->id, ['expected_count' => 55]);

        $onlineOnlyHeadroom = app(TermCapacityHeadroomProjection::class)->forTerm($term);
        $this->assertSame(0, $onlineOnlyHeadroom['room_seats']['over_capacity_sections']);
        $this->assertSame('healthy', $onlineOnlyHeadroom['room_seats']['status']);

        [$f2fCourse, $f2fSpec] = $this->createCourseWithComponent('IT 102', 'Computing Lab', 3.0, 3.0, '2x90');
        $f2fEntry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($f2fSpec, 'courseSpecification')->create(['year_level' => 'First Year', 'term_type' => $term->type]);
        $f2fOffering = TermOffering::factory()->for($term)->for($f2fEntry, 'curriculumEntry')->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StatePendingScheduling]);
        $splitSection = $this->createConfirmedSection($f2fOffering, $package, $f2fSpec, 'IT102-A');
        $splitSection->update(['capacity' => 35]);
        SectionDeliveryGroup::factory()->for($splitSection)->create([
            'name' => 'IT102-A',
            'expected_count' => 35,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);

        $splitHeadroom = app(TermCapacityHeadroomProjection::class)->forTerm($term);
        $this->assertSame(0, $splitHeadroom['room_seats']['over_capacity_sections']);
        $this->assertSame(35, $splitHeadroom['room_seats']['largest_section']);

        $oversizedSection = $this->createConfirmedSection($f2fOffering, $package, $f2fSpec, 'IT102-BIG');
        $oversizedSection->update(['capacity' => 45]);
        SectionDeliveryGroup::factory()->for($oversizedSection)->create([
            'name' => 'IT102-BIG',
            'expected_count' => 45,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);

        $overHeadroom = app(TermCapacityHeadroomProjection::class)->forTerm($term);
        $this->assertSame(1, $overHeadroom['room_seats']['over_capacity_sections']);
        $this->assertSame('over', $overHeadroom['room_seats']['status']);
    }

    public function test_ready_projection_flags_unconfirmed_classes_when_published_timetable_has_zero_enrollments(): void
    {
        $registrar = $this->actingAsRegistrar();
        [$term, $package, , $curriculum] = $this->activeTermContext();

        Room::factory()->create(['is_active' => true, 'room_type' => Room::TypeLectureRoom, 'capacity' => 40]);
        $faculty = $this->makeFaculty('Liza', 'Torres');
        [$course, $spec] = $this->createCourseWithComponent('IT 205', 'Database Systems', 3.0, 3.0, '2x90');
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->for($spec, 'courseSpecification')->create(['year_level' => 'Second Year', 'term_type' => $term->type]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['modality' => TermOffering::ModalityFaceToFace, 'state' => TermOffering::StatePendingScheduling]);

        $confirmed = $this->createConfirmedSection($offering, $package, $spec, 'IT205-A');
        SectionDeliveryGroup::factory()->for($confirmed)->create([
            'name' => 'IT205-A',
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);

        Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'IT205-DRAFT',
            'term_calendar_package_id' => $package->id,
            'course_specification_id' => $spec->id,
            'capacity' => 30,
            'state' => Section::StatePlanned,
            'confirmed_at' => null,
        ]);

        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $course->id, 'is_active' => true]);
        app(RecordFacultyAvailabilityDeclaration::class)->execute($term, $faculty, $faculty, availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved);

        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'candidate_state' => 'Accepted',
            'published_by' => $registrar->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        PublishedTimetableVersion::factory()->for($term)->create([
            'schedule_run_id' => $run->id,
            'state' => PublishedTimetableVersion::StatePublished,
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);

        $readiness = app(ReadyTermPlanningProjection::class)->forTerm($term);

        $this->assertFalse($readiness['ready']);
        $this->assertContains('classes_not_confirmed', array_column($readiness['blockers'], 'code'));
    }

    public function test_school_resources_renders_room_type_badges_and_actions_column_labels(): void
    {
        $this->actingAsRegistrar();
        $this->makeFaculty('Nora', 'Santos');
        Room::factory()->create([
            'code' => 'CL-101',
            'name' => 'Computer Lab 1',
            'room_type' => Room::TypeComputerLaboratory,
            'capacity' => 35,
            'is_active' => true,
        ]);

        Livewire::withQueryParams(['tab' => 'rooms'])
            ->test(SchoolResources::class)
            ->assertSuccessful()
            ->assertSee('Actions')
            ->assertSee('CL-101');

        Livewire::withQueryParams(['tab' => 'faculty'])
            ->test(SchoolResources::class)
            ->assertSuccessful()
            ->assertSee('Actions')
            ->assertSee('Nora Santos');
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

    /** @return array{Term, TermCalendarPackage, Program, CurriculumVersion} */
    private function activeTermContext(): array
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

        foreach ([1, 3] as $day) {
            TermTeachingGridRow::factory()->create([
                'term_calendar_package_id' => $package->id,
                'day_of_week' => $day,
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
            ]);
        }

        $program = Program::factory()->create(['code' => 'BSIT93Q', 'name' => 'BS Information Technology']);
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT93Q Curriculum 2026',
            'state' => CurriculumVersion::StateActive,
        ]);

        return [$term, $package, $program, $curriculum];
    }

    /** @return array{Course, CourseSpecification} */
    private function createCourseWithComponent(string $code, string $title, float $units, float $weeklyHours, string $pattern): array
    {
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
