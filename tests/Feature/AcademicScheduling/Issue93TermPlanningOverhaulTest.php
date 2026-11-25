<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Filament\Pages\SchoolResources;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\ScheduleGenerationRuns\Schemas\PublishedScheduleRevisionForm;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
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
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue93TermPlanningOverhaulTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function school_resources_separates_global_rooms_and_qualified_faculty_with_full_titles_and_head_read_only(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $room = Room::factory()->create(['code' => 'SR-101', 'name' => 'School Resource Lecture', 'is_active' => true]);
        $faculty = User::factory()->create([...User::staffNamePayload('Maria', null, 'Reyes'), 'status' => User::StatusActive]);
        $faculty->assignRole(Role::findOrCreate(User::StaffRoleFaculty, 'web'));

        $activeCourse = Course::factory()->create(['code' => 'CS 201']);
        CourseSpecification::factory()->for($activeCourse)->create([
            'state' => CourseSpecification::StateActive,
            'title' => 'Data Structures & Algorithms',
        ]);

        $draftCourse = Course::factory()->create(['code' => 'CS 202']);
        CourseSpecification::factory()->for($draftCourse)->create([
            'state' => CourseSpecification::StateDraft,
            'title' => 'Discrete Mathematics Draft',
        ]);

        Livewire::withQueryParams(['tab' => 'rooms'])->test(SchoolResources::class)
            ->assertOk()
            ->assertSee('Rooms')
            ->assertSee('Qualified faculty')
            ->assertActionVisible(TestAction::make('addRoom')->table())
            ->assertTableActionVisible('editRoom', $room);

        $facultyTab = Livewire::withQueryParams(['tab' => 'faculty'])->test(SchoolResources::class)
            ->assertOk()
            ->assertActionVisible(TestAction::make('assignSubjects')->table())
            ->mountAction(TestAction::make('editFacultySubjects')->table($faculty))
            ->assertMountedActionModalSee('tala-card-picker')
            ->assertMountedActionModalSee('CS 201 · Data Structures & Algorithms')
            ->assertMountedActionModalSee('CS 202 · Discrete Mathematics Draft')
            ->setActionData([
                'course_ids' => [(string) $activeCourse->id, (string) $draftCourse->id],
                'notes' => 'Global qualification assignment',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $facultyTab->assertSee('2 subjects');
        $this->assertTrue(
            FacultyQualification::query()
                ->where('faculty_user_id', $faculty->id)
                ->where('course_id', $draftCourse->id)
                ->where('is_active', true)
                ->exists(),
        );

        $this->staff(User::StaffRoleAcademicHead);

        Livewire::withQueryParams(['tab' => 'rooms'])->test(SchoolResources::class)
            ->assertOk()
            ->assertActionVisible('returnToOversight')
            ->assertActionHidden(TestAction::make('addRoom')->table())
            ->assertTableActionHidden('editRoom', $room);

        Livewire::withQueryParams(['tab' => 'faculty'])->test(SchoolResources::class)
            ->assertOk()
            ->assertActionHidden(TestAction::make('assignSubjects')->table())
            ->assertTableActionHidden('editFacultySubjects', $faculty);
    }

    #[Test]
    public function term_resources_retires_unavailable_tab_scopes_room_closures_and_marks_needed_this_term(): void
    {
        $this->staff(User::StaffRoleRegistrar);

        $term = Term::factory()->create(['state' => Term::StateActive]);
        $room = Room::factory()->create(['code' => 'LAB-401', 'is_active' => true]);
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $faculty->assignRole(Role::findOrCreate(User::StaffRoleFaculty, 'web'));

        $offeredCourse = Course::factory()->create(['code' => 'IT 310']);
        $offeredSpec = CourseSpecification::factory()->for($offeredCourse)->create([
            'state' => CourseSpecification::StateDraft,
            'title' => 'Systems Integration Draft',
        ]);
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($offeredSpec, 'courseSpecification')->create([
            'year_level' => 'Third Year',
            'term_type' => $term->type,
        ]);
        TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();

        // Legacy ?resourceView=unavailable falls back cleanly to Rooms without rendering Unavailable times tab.
        Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'unavailable'])->test(TermResources::class)
            ->assertOk()
            ->assertSet('resourceView', 'rooms')
            ->assertDontSee('Unavailable times')
            ->assertActionDoesNotExist('editDefaultMaxUnits')
            ->mountTableAction('roomClosures', $room)
            ->setActionData([
                'closures' => [
                    [
                        'day_of_week' => '5',
                        'starts_at' => '13:00',
                        'ends_at' => '15:00',
                        'is_active' => true,
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $closure = CalendarEvent::query()
            ->where('term_id', $term->id)
            ->where('room_id', $room->id)
            ->sole();

        $this->assertSame(CalendarEvent::ScopeRoom, $closure->scope_type);
        $this->assertSame(CalendarEvent::TypeUnavailable, $closure->event_type);

        Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'faculty'])->test(TermResources::class)
            ->assertOk()
            ->mountTableAction('editFacultySubjects', 'faculty-'.$faculty->id)
            ->assertMountedActionModalSee('IT 310 · Systems Integration Draft')
            ->assertMountedActionModalSee('Needed this term');
    }

    #[Test]
    public function lifecycle_gating_locks_published_and_closed_terms_syncs_dates_and_supports_close_and_delete_draft(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        // 1. ActivateTermCalendarPackage syncs Term dates to classes_start_on / classes_end_on.
        $year = AcademicYear::factory()->create(['starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']);
        $term = Term::factory()->for($year)->create([
            'state' => Term::StateDraft,
            'starts_on' => '2027-08-01',
            'ends_on' => '2027-12-20',
        ]);
        $draftPkg = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'administrative_starts_on' => '2027-07-15',
            'administrative_ends_on' => '2027-12-31',
            'classes_start_on' => '2027-08-10',
            'classes_end_on' => '2027-12-12',
        ]);
        TermTeachingGridRow::factory()->for($draftPkg, 'package')->create(['day_of_week' => 1, 'starts_at' => '07:00:00', 'ends_at' => '19:00:00']);

        $activePkg = app(ActivateTermCalendarPackage::class)->execute($draftPkg, $registrar);
        $this->assertSame('2027-08-10', $term->fresh()->starts_on->toDateString());
        $this->assertSame('2027-12-12', $term->fresh()->ends_on->toDateString());

        // 2. Published timetable hard-locks pre-publish mutations across Calendar, Classes, and Resources.
        $room = Room::factory()->create(['is_active' => true]);
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $faculty->assignRole(Role::findOrCreate(User::StaffRoleFaculty, 'web'));

        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT 111']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        CourseComponent::factory()->for($spec, 'courseSpecification')->create();
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => 'First Year',
            'term_type' => $term->type,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $activePkg->id,
            'course_specification_id' => $spec->id,
            'confirmed_at' => now(),
            'confirmed_by' => $registrar->id,
        ]);
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusPublished,
        ]);
        PublishedTimetableVersion::factory()->for($term)->for($run, 'scheduleRun')->create([
            'state' => PublishedTimetableVersion::StatePublished,
        ]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->assertActionHidden('saveAndActivateCalendar')
            ->assertSee('Official timetable is published');

        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertActionHidden('recordCohort')
            ->assertActionHidden('loadCurriculumSubjects')
            ->assertActionHidden('addClass')
            ->assertTableActionHidden('editClass', $section)
            ->assertSeeHtml('Timetable &rarr; Revise official schedule');

        Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'faculty'])->test(TermResources::class)
            ->assertActionHidden('sendAvailabilityReminder');

        Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'rooms'])->test(TermResources::class)
            ->assertTableActionHidden('roomClosures', $room);

        // 3. Close term via TermPlanningWorkbench locks everything including Timetable revisions and Calendar activation.
        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->callAction('closeTerm')
            ->assertHasNoActionErrors();

        $this->assertSame(Term::StateClosed, $term->fresh()->state);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->assertActionHidden('saveAndActivateCalendar')
            ->assertActionHidden('addOperationalDates')
            ->assertSee('This term is closed and read-only.');

        Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'faculty'])->test(TermResources::class)
            ->assertActionHidden(TestAction::make('assignSubjects')->table())
            ->assertTableActionHidden('editFacultySubjects', 'faculty-'.$faculty->id)
            ->assertSee('Closed term · Read-only');

        Livewire::withQueryParams(['termId' => $term->id])->test(TermTimetable::class)
            ->assertActionHidden('generateTimetable')
            ->assertActionHidden('revisePublishedSchedule');

        $newDraftPkg = TermCalendarPackage::factory()->for($term->fresh())->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 2,
        ]);
        try {
            app(ActivateTermCalendarPackage::class)->execute($newDraftPkg, $registrar);
            $this->fail('Closed terms must reject calendar activation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term_id', $exception->errors());
        }

        // 4. Delete unused draft term.
        $unusedDraft = Term::factory()->for($year)->create([
            'state' => Term::StateDraft,
            'type' => Term::TypeSecondSemester,
            'label' => 'Unused Draft Term',
        ]);

        Livewire::withQueryParams(['termId' => $unusedDraft->id])->test(TermCalendar::class)
            ->callAction('deleteDraftTerm')
            ->assertHasNoActionErrors();

        $this->assertNull(Term::query()->find($unusedDraft->id));
    }

    #[Test]
    public function add_student_section_persists_year_level_auto_creates_classes_and_edit_class_autofills_cohort_counts(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $term = Term::factory()->create(['state' => Term::StateActive, 'type' => Term::TypeFirstSemester]);
        $pkg = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => now(),
        ]);
        TermTeachingGridRow::factory()->for($pkg, 'package')->create(['day_of_week' => 1, 'starts_at' => '07:00:00', 'ends_at' => '19:00:00']);
        Room::factory()->create(['capacity' => 45, 'is_active' => true]);

        $program = Program::factory()->create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'duration_years' => 4]);
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT 201']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive, 'title' => 'Object-Oriented Programming']);
        CourseComponent::factory()->for($spec, 'courseSpecification')->create(['component_type' => CourseComponent::TypeLecture]);
        CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => 'Second Year',
            'term_type' => Term::TypeFirstSemester,
        ]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->mountAction('recordCohort')
            ->setActionData([
                'curriculum_version_id' => $curriculum->id,
                'year_level' => 2,
                'reference' => 'BSIT-2A',
                'confirmed_count' => 32,
                'section_count' => 1,
                'auto_create_classes' => true,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Student section recorded');

        $cohort = TermCohort::query()->where('term_id', $term->id)->where('reference', 'BSIT-2A')->sole();
        $this->assertSame(2, $cohort->year_level);

        $createdSection = Section::query()
            ->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
            ->sole();

        $this->assertNotNull($createdSection->confirmed_at);
        $this->assertSame(32, $createdSection->capacity);

        // Class details redesigned 3xl modal omits Record history dropdown and renders inline metadata.
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->mountTableAction('viewClass', $createdSection)
            ->assertMountedActionModalSee('Student sections served')
            ->assertMountedActionModalSee('Meeting requirements')
            ->assertMountedActionModalDontSee('Record history');

        // Edit class formats cohort option with student count and auto-fills expected_count on change.
        $secondCohort = TermCohort::factory()->for($term)->for($curriculum)->create([
            'program_id' => $program->id,
            'reference' => 'BSIT-2B',
            'year_level' => 2,
            'confirmed_count' => 24,
            'confirmed_at' => now(),
            'confirmed_by' => $registrar->id,
        ]);

        $editModal = Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->mountTableAction('editClass', $createdSection)
            ->assertMountedActionModalSee('tala-cohort-repeater')
            ->assertMountedActionModalSee('BSIT-2A');

        $cohortsRaw = $editModal->get('mountedActions.0.data.cohorts');
        $firstKey = array_key_first($cohortsRaw);
        $this->assertNotNull($firstKey);

        $editModal->set("mountedActions.0.data.cohorts.{$firstKey}.term_cohort_id", $secondCohort->id);

        $cohortsState = $editModal->get('mountedActions.0.data.cohorts');
        $this->assertSame(24, (int) $cohortsState[$firstKey]['expected_count']);
        $modalHtml = $editModal->getMountedActionModalHtml();
        $decodedModal = json_decode('"'.str_replace('"', '\\"', $modalHtml).'"') ?: $modalHtml;
        $this->assertTrue(
            str_contains($modalHtml, 'BSIT-2B · 24 students')
            || str_contains($modalHtml, 'BSIT-2B \u00b7 24 students')
            || str_contains($decodedModal, 'BSIT-2B · 24 students'),
        );
    }

    #[Test]
    public function published_revision_form_and_compiled_css_preserve_layout_and_zero_blue_rules(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $term = Term::factory()->create(['state' => Term::StateActive]);
        $pkg = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);
        $room = Room::factory()->create(['code' => 'RM-305', 'is_active' => true]);
        $faculty = User::factory()->create([...User::staffNamePayload('Elena', null, 'Cruz'), 'status' => User::StatusActive]);
        $faculty->assignRole(Role::findOrCreate(User::StaffRoleFaculty, 'web'));

        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT 401']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive, 'title' => 'Capstone Project 1']);
        $component = CourseComponent::factory()->for($spec, 'courseSpecification')->create();
        $entry = CurriculumEntry::factory()->for($curriculum)->for($spec, 'courseSpecification')->create([
            'year_level' => 'Fourth Year',
            'term_type' => $term->type,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'term_calendar_package_id' => $pkg->id,
            'course_specification_id' => $spec->id,
            'code' => 'BSIT-4A',
            'confirmed_at' => now(),
            'confirmed_by' => $registrar->id,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section, 'section')->create();
        $demand = SchedulingDemand::factory()->for($group, 'sectionDeliveryGroup')->create([
            'term_offering_id' => $offering->id,
            'course_component_id' => $component->id,
        ]);
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'status' => ScheduleGenerationRun::StatusPublished,
        ]);
        $version = PublishedTimetableVersion::factory()->for($term)->for($run, 'scheduleRun')->create([
            'state' => PublishedTimetableVersion::StatePublished,
        ]);
        SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $version->id,
            'scheduling_demand_id' => $demand->id,
            'room_id' => $room->id,
            'faculty_user_id' => $faculty->id,
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:30:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);

        Livewire::withQueryParams(['termId' => $term->id, 'timetableState' => 'official'])->test(TermTimetable::class)
            ->assertOk()
            ->assertSeeHtml('tala-stage-bar')
            ->assertDontSeeHtml('fi-page-sub-navigation-tabs')
            ->mountAction('revisePublishedSchedule')
            ->assertMountedActionModalSee('tala-card-picker')
            ->assertMountedActionModalSee('tala-revision-meeting-checklist')
            ->assertMountedActionModalSee('IT 401 · Capstone Project 1 (BSIT-4A)')
            ->assertMountedActionModalSee('RM-305 · Elena Cruz · Meeting 1');

        $this->assertNotEmpty(PublishedScheduleRevisionForm::schema($run));

        // Verify compiled Vite theme CSS and static Filament/Catalog CSS follow the approved rules.
        $themeCssFiles = File::glob(public_path('build/assets/theme-*.css'));
        $this->assertNotEmpty($themeCssFiles, 'Compiled Vite theme CSS must exist in public/build/assets.');
        $compiledThemeCss = File::get($themeCssFiles[0]);
        $filamentCss = File::get(public_path('css/tala-filament.css'));
        $catalogCss = File::get(public_path('css/tala-catalog.css'));

        $this->assertStringContainsString('.tala-stage-bar', $compiledThemeCss);
        $this->assertStringContainsString('overflow:clip', str_replace(' ', '', $compiledThemeCss));
        $this->assertStringNotContainsString('.tala-studio-canvas.is-week .tala-timetable-list{display:none}', str_replace(["\r", "\n", '  '], ['', '', ' '], $compiledThemeCss));
        $this->assertStringNotContainsString('#2563eb', strtolower($compiledThemeCss));
        $this->assertStringNotContainsString('#3b82f6', strtolower($compiledThemeCss));

        $this->assertStringContainsString('.tala-card-picker', $filamentCss);
        $this->assertStringContainsString('.fi-input-wrp:not(.fi-disabled):focus-within', $filamentCss);
        $this->assertStringNotContainsString('#2563eb', strtolower($filamentCss.$catalogCss));
        $this->assertStringNotContainsString('#3b82f6', strtolower($filamentCss.$catalogCss));
    }

    private function staff(string $roleName): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        if ($roleName === User::StaffRoleRegistrar) {
            $role->givePermissionTo([
                Permission::findOrCreate('manage-schedules', 'web'),
                Permission::findOrCreate('approve-documents', 'web'),
                Permission::findOrCreate('manage-admission-setup', 'web'),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $roleName]);

        return $user;
    }
}
