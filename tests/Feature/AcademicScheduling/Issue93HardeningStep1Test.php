<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Grades\GradeWindowService;
use App\Actions\Grades\SubmitGradeRoster;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermOverview;
use App\Models\AcademicYear;
use App\Models\AdmissionCycle;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyTermLoadOverride;
use App\Models\GradeRoster;
use App\Models\GraduationReviewBatch;
use App\Models\LateGradeAuthorization;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Issue93HardeningStep1Test extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
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

    public function test_close_term_blocks_open_published_admission_cycle_but_succeeds_when_published_cycle_window_already_closed(): void
    {
        $registrar = $this->actingAsRegistrar();
        $year = AcademicYear::factory()->create([
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'state' => AcademicYear::StateActive,
        ]);
        $term = Term::factory()->for($year)->create([
            'state' => Term::StateActive,
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-12-15',
        ]);

        $cycle = AdmissionCycle::factory()->published()->create([
            'term_id' => $term->id,
            'opens_at' => now()->subWeek(),
            'closes_at' => now()->addWeek(),
            'registrar_owner_id' => $registrar->id,
        ]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->callAction('closeTerm')
            ->assertHasErrors(['state']);

        $this->assertSame(Term::StateActive, $term->fresh()->state);

        $cycle->update(['closes_at' => now()->subMinute()]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->callAction('closeTerm')
            ->assertHasNoActionErrors();

        $this->assertSame(Term::StateClosed, $term->fresh()->state);
    }

    public function test_closed_term_blocks_enrollment_start_and_grade_roster_submission_even_with_late_authority(): void
    {
        $registrar = $this->actingAsRegistrar();
        $faculty = $this->makeFaculty('Maria', 'Santos');

        $term = Term::factory()->create(['state' => Term::StateClosed]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDays(7)->toDateString(),
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeGradeEntry,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDays(7)->toDateString(),
        ]);

        $this->assertNull(app(CalendarPhaseGateService::class)->activePackage($term->id));

        $profile = StudentProfile::factory()->create();

        try {
            app(StartRegistrationCase::class)->forContinuingStudent(
                studentProfile: $profile,
                term: $term,
                actor: $registrar,
                startMethod: 'LateAuthority',
                authorityReference: 'MEMO-LATE-001',
            );
            $this->fail('Expected StartRegistrationCase to reject a closed term even with LateAuthority.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term', $exception->errors());
        }

        $offering = TermOffering::factory()->for($term)->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen]);
        $assignment = ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'authority_reference' => 'ASSIGN-01',
            'effective_at' => now(),
            'assigned_by' => $registrar->id,
            'assigned_at' => now(),
        ]);
        $roster = GradeRoster::factory()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'teaching_assignment_id' => $assignment->id,
            'state' => GradeRoster::StateDraft,
        ]);
        LateGradeAuthorization::query()->create([
            'grade_roster_id' => $roster->id,
            'term_offering_id' => $offering->id,
            'faculty_user_id' => $faculty->id,
            'grading_period' => LateGradeAuthorization::PeriodFinal,
            'state' => LateGradeAuthorization::StateActive,
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addDay(),
            'approved_by' => $registrar->id,
            'reason' => 'Late authority test',
        ]);

        $this->assertFalse(app(GradeWindowService::class)->isOpen($roster, 'final'));

        try {
            app(SubmitGradeRoster::class)->execute($roster, $faculty);
            $this->fail('Expected SubmitGradeRoster to reject a closed term even with LateGradeAuthorization.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term', $exception->errors());
        }
    }

    public function test_term_settings_blocks_lowering_default_max_units_below_published_faculty_load_without_override(): void
    {
        $registrar = $this->actingAsRegistrar();
        $faculty = $this->makeFaculty('Jose', 'Rizal');

        $year = AcademicYear::factory()->create([
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'state' => AcademicYear::StateActive,
        ]);
        $term = Term::factory()->for($year)->create([
            'state' => Term::StateActive,
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-12-15',
            'default_max_units' => 24,
        ]);

        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'classes_start_on' => '2026-08-10',
            'classes_end_on' => '2026-12-15',
            'activated_at' => now(),
        ]);

        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
        ]);
        $publishedVersion = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'load-floor-v1-'.$term->id),
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);

        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->for($program)->create();
        $room = Room::factory()->create();

        foreach ([1, 2] as $index) {
            $course = Course::factory()->create(['code' => 'LOAD10'.$index]);
            $spec = CourseSpecification::factory()->for($course)->create([
                'credit_units' => 3.00,
                'state' => CourseSpecification::StateActive,
            ]);
            $component = CourseComponent::factory()->for($spec, 'courseSpecification')->create([
                'component_type' => CourseComponent::TypeLecture,
                'weekly_contact_hours' => 3.00,
            ]);
            $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
                'term_type' => $term->type,
                'sequence' => $index,
            ]);
            $offering = TermOffering::factory()->for($term)->for($entry)->create([
                'state' => TermOffering::StateScheduled,
            ]);
            $section = Section::factory()->for($offering, 'termOffering')->create([
                'term_calendar_package_id' => $package->id,
                'state' => Section::StateOpen,
            ]);
            $deliveryGroup = SectionDeliveryGroup::factory()->for($section)->create();
            $demand = SchedulingDemand::factory()->create([
                'term_offering_id' => $offering->id,
                'course_component_id' => $component->id,
                'section_delivery_group_id' => $deliveryGroup->id,
            ]);

            SectionMeeting::query()->create([
                'schedule_run_id' => $run->id,
                'published_timetable_version_id' => $publishedVersion->id,
                'scheduling_demand_id' => $demand->id,
                'meeting_sequence' => 1,
                'faculty_user_id' => $faculty->id,
                'room_id' => $room->id,
                'day_of_week' => $index,
                'starts_at' => '08:00:00',
                'ends_at' => '11:00:00',
                'modality' => TermOffering::ModalityFaceToFace,
                'state' => SectionMeeting::StateActive,
                'published_at' => now(),
            ]);
        }

        Livewire::test(TermCalendar::class, ['termId' => $term->id])
            ->callAction('termSettings', data: [
                'default_max_units' => 3,
            ])
            ->assertHasActionErrors(['default_max_units']);

        $this->assertEquals(24.0, (float) $term->fresh()->default_max_units);

        Livewire::test(TermCalendar::class, ['termId' => $term->id])
            ->callAction('termSettings', data: [
                'default_max_units' => 6,
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(6.0, (float) $term->fresh()->default_max_units);

        FacultyTermLoadOverride::query()->create([
            'faculty_user_id' => $faculty->id,
            'term_id' => $term->id,
            'default_max_units_snapshot' => 6.00,
            'approved_overload_units' => 3.00,
            'authority' => 'DEAN-OVR-01',
            'reason' => 'Approved overload',
            'is_active' => true,
        ]);

        Livewire::test(TermCalendar::class, ['termId' => $term->id])
            ->callAction('termSettings', data: [
                'default_max_units' => 3,
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(3.0, (float) $term->fresh()->default_max_units);
    }

    public function test_term_overview_blocks_deleting_academic_year_with_graduation_review_batch_and_blocks_closing_with_active_terms(): void
    {
        $this->actingAsRegistrar();

        $ayWithGradBatch = AcademicYear::factory()->create([
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'state' => AcademicYear::StateDraft,
        ]);
        $otherYearTerm = Term::factory()->create();
        GraduationReviewBatch::query()->create([
            'academic_year_id' => $ayWithGradBatch->id,
            'term_id' => $otherYearTerm->id,
            'name' => 'Batch 2026-2027',
            'state' => GraduationReviewBatch::StateOpen,
        ]);

        Livewire::withQueryParams(['overviewTab' => 'academic-years'])->test(TermOverview::class)
            ->assertTableActionHidden('deleteAcademicYear', $ayWithGradBatch);

        $ayWithActiveTerm = AcademicYear::factory()->create([
            'starts_on' => '2027-08-01',
            'ends_on' => '2028-05-31',
            'state' => AcademicYear::StateActive,
        ]);
        $activeTerm = Term::factory()->for($ayWithActiveTerm)->create([
            'state' => Term::StateActive,
            'starts_on' => '2027-08-10',
            'ends_on' => '2027-12-15',
        ]);

        Livewire::withQueryParams(['overviewTab' => 'academic-years'])->test(TermOverview::class)
            ->callTableAction('editAcademicYear', $ayWithActiveTerm, data: [
                'label' => $ayWithActiveTerm->label,
                'starts_on' => '2027-08-01',
                'ends_on' => '2028-05-31',
                'state' => AcademicYear::StateClosed,
            ])
            ->assertHasErrors(['state']);

        $this->assertSame(AcademicYear::StateActive, $ayWithActiveTerm->fresh()->state);

        $activeTerm->update(['state' => Term::StateClosed]);

        Livewire::withQueryParams(['overviewTab' => 'academic-years'])->test(TermOverview::class)
            ->callTableAction('editAcademicYear', $ayWithActiveTerm, data: [
                'label' => $ayWithActiveTerm->label,
                'starts_on' => '2027-08-01',
                'ends_on' => '2028-05-31',
                'state' => AcademicYear::StateClosed,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(AcademicYear::StateClosed, $ayWithActiveTerm->fresh()->state);
    }

    public function test_activate_term_calendar_package_rejects_class_dates_outside_academic_year_bounds(): void
    {
        $registrar = $this->actingAsRegistrar();
        $year = AcademicYear::factory()->create([
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'state' => AcademicYear::StateActive,
        ]);
        $term = Term::factory()->for($year)->create([
            'state' => Term::StateDraft,
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-12-15',
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'administrative_starts_on' => '2026-07-15',
            'administrative_ends_on' => '2026-12-15',
            'classes_start_on' => '2026-07-15',
            'classes_end_on' => '2026-12-15',
        ]);
        TermTeachingGridRow::factory()->for($package, 'package')->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'breaks' => [],
        ]);

        try {
            app(ActivateTermCalendarPackage::class)->execute($package, $registrar);
            $this->fail('Expected ActivateTermCalendarPackage to reject class dates outside the academic year.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('classes_start_on', $exception->errors());
        }
    }

    public function test_activate_term_calendar_package_auto_promotes_draft_academic_year_to_active(): void
    {
        $registrar = $this->actingAsRegistrar();
        $year = AcademicYear::factory()->create([
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'state' => AcademicYear::StateDraft,
        ]);
        $term = Term::factory()->for($year)->create([
            'state' => Term::StateDraft,
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-12-15',
        ]);
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'administrative_starts_on' => '2026-08-10',
            'administrative_ends_on' => '2026-12-15',
            'classes_start_on' => '2026-08-10',
            'classes_end_on' => '2026-12-15',
        ]);
        TermTeachingGridRow::factory()->for($package, 'package')->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
            'breaks' => [],
        ]);

        app(ActivateTermCalendarPackage::class)->execute($package, $registrar);

        $this->assertSame(Term::StateActive, $term->fresh()->state);
        $this->assertSame(AcademicYear::StateActive, $year->fresh()->state);
    }
}
