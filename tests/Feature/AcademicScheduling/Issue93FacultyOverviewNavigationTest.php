<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeclaration;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\FacultyOverview;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\MyAvailability;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FacultyAvailabilityDeadline;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Program;
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
use App\Models\TermOffering;
use App\Models\User;
use App\Support\WorkspaceNavigationSearchProvider;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue93FacultyOverviewNavigationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());

        foreach (User::staffRoleNames() as $role) {
            Role::findOrCreate($role, 'web');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[Test]
    public function faculty_lands_on_overview_and_only_faculty_context_can_access_it(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $dual = $this->staff(User::StaffRoleFaculty);
        $dual->assignRole(User::StaffRoleRegistrar);

        $resolver = app(WorkspaceContextResolver::class);
        $this->assertSame('/admin/faculty-overview', $resolver->destinationFor($faculty, User::StaffRoleFaculty));

        $this->actingAs($faculty)
            ->get(FacultyOverview::getUrl())
            ->assertOk();

        $this->actingAs($registrar)
            ->get(FacultyOverview::getUrl())
            ->assertForbidden();

        // Dual-role user with Registrar selected cannot access FacultyOverview.
        $this->actingAs($dual)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar])
            ->get(FacultyOverview::getUrl())
            ->assertForbidden();

        // Dual-role user with Faculty selected can access FacultyOverview.
        $this->actingAs($dual)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleFaculty])
            ->get(FacultyOverview::getUrl())
            ->assertOk();
    }

    #[Test]
    public function overview_auto_resolves_active_term_without_term_gate_and_shows_empty_state_when_no_term_exists(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);

        TermCalendarPackage::query()->update(['state' => TermCalendarPackage::StateClosed]);
        Term::query()->update(['state' => Term::StateClosed]);

        Livewire::actingAs($faculty)
            ->test(FacultyOverview::class)
            ->assertSet('termId', null)
            ->assertSee('No active term yet')
            ->assertDontSee('Choose a term to view your overview');

        $packageA = $this->activePackage($registrar);
        $packageA->term->update(['starts_on' => '2026-01-10', 'ends_on' => '2026-05-15', 'state' => Term::StateActive]);

        $packageB = $this->activePackage($registrar);
        $packageB->term->update(['starts_on' => '2026-08-10', 'ends_on' => '2026-12-15', 'state' => Term::StateActive]);

        $this->assertSame(
            2,
            TermCalendarPackage::query()->where('state', TermCalendarPackage::StateActive)->distinct()->count('term_id'),
        );

        // Auto-resolves the newest active-calendar term with zero dropdown gate.
        Livewire::actingAs($faculty)
            ->test(FacultyOverview::class)
            ->assertSet('termId', $packageB->term_id)
            ->assertDontSee('Choose a term to view your overview')
            ->assertDontSeeHtml('id="term-context-switcher"')
            ->assertSee($packageB->term->label)
            ->assertSee('Weekly schedule')
            ->assertSee('Schedule not published yet')
            ->assertSee('Open full schedule')
            ->assertSee('Academic calendar')
            ->assertSee('My availability')
            ->assertSee('Not submitted yet')
            ->assertSee('Class list & grades')
            ->assertSee('0 assigned classes');

        // Explicit ?termId= deep links still load that term, and unknown IDs return 404.
        Livewire::actingAs($faculty)
            ->test(FacultyOverview::class, ['termId' => $packageA->term_id])
            ->assertSet('termId', $packageA->term_id)
            ->assertSee($packageA->term->label);

        $invalidTermId = (int) Term::query()->max('id') + 1;
        Livewire::withQueryParams(['termId' => $invalidTermId])
            ->actingAs($faculty)
            ->test(FacultyOverview::class)
            ->assertNotFound();

        $this->assertNotSame($packageA->term_id, $packageB->term_id);
    }

    #[Test]
    public function overview_shows_caught_up_state_and_plain_availability_labels_without_roster_jargon(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $fixture = $this->scheduleFixture($registrar, $faculty);
        $term = $fixture['term'];

        TermCalendarWindow::query()->create([
            'term_calendar_package_id' => $fixture['package']->id,
            'window_type' => TermCalendarWindow::TypeExaminationPeriod,
            'opens_on' => $term->ends_on->copy()->subDays(5)->toDateString(),
            'closes_on' => $term->ends_on->toDateString(),
        ]);

        FacultyAvailabilityDeadline::query()->create([
            'term_id' => $term->id,
            'due_at' => Carbon::parse('2026-08-15 17:00:00', 'Asia/Manila')->utc(),
            'recorded_by' => $registrar->id,
        ]);

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
            ],
        );

        $component = Livewire::actingAs($faculty)
            ->test(FacultyOverview::class)
            ->assertSet('termId', $term->id)
            ->assertDontSee('Needs your action')
            ->assertSee('Weekly schedule')
            ->assertSee('1 class')
            ->assertSee('1 weekly meeting')
            ->assertSee($fixture['course']->code)
            ->assertSee('Open full schedule')
            ->assertSee('Academic calendar')
            ->assertSee('My availability')
            ->assertSee('Available on selected times')
            ->assertDontSee('Reply by Aug 15, 2026')
            ->assertDontSee('Update availability')
            ->assertDontSee('Update times')
            ->assertSee('Class list & grades')
            ->assertSee('0 assigned classes');

        // Verify zero occurrences of "roster" in visible UI text (excluding URL slugs like faculty-grade-roster).
        $visibleText = preg_replace('/href="[^"]*"/i', '', $component->html());
        $this->assertStringNotContainsStringIgnoringCase('roster', strip_tags((string) $visibleText));

        // Verify the other plain saved status labels via backend declaration recording.
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            correctionReason: 'Available across all hours',
            availabilityMode: FacultyAvailabilityDeclaration::ModeAnyApproved,
        );
        Livewire::actingAs($faculty)->test(FacultyOverview::class, ['termId' => $term->id])
            ->assertSee('Available during all teaching hours')
            ->assertDontSee('Update availability');

        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            correctionReason: 'On study leave',
            availabilityMode: FacultyAvailabilityDeclaration::ModeCannotTeach,
        );
        Livewire::actingAs($faculty)->test(FacultyOverview::class, ['termId' => $term->id])
            ->assertSee('Unavailable this term')
            ->assertDontSee('Update availability');
    }

    #[Test]
    public function overview_ranks_urgency_and_surfaces_all_four_needs_your_action_categories(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $fixture = $this->scheduleFixture($registrar, $faculty);
        $term = $fixture['term'];

        FacultyAvailabilityDeadline::query()->create([
            'term_id' => $term->id,
            'due_at' => Carbon::parse('2026-08-20 17:00:00', 'Asia/Manila')->utc(),
            'recorded_by' => $registrar->id,
        ]);

        // 1. No availability yet + schedule revision event -> availability nudge + schedule revision surfaced.
        ScheduleRevisionEvent::query()->create([
            'term_id' => $term->id,
            'section_meeting_id' => $fixture['meeting']->id,
            'change_type' => ScheduleRevisionEvent::ChangeTime,
            'reason' => 'Lab room reassignment',
            'effective_date' => '2026-08-25',
            'changed_by' => $registrar->id,
            'old_snapshot_json' => [
                'faculty_user_id' => $faculty->id,
                'day_of_week' => 3,
                'starts_at' => '13:00:00',
                'ends_at' => '15:00:00',
            ],
            'new_snapshot_json' => [
                'faculty_user_id' => $faculty->id,
                'day_of_week' => 1,
                'starts_at' => '08:00:00',
                'ends_at' => '11:00:00',
            ],
        ]);

        Livewire::actingAs($faculty)
            ->test(FacultyOverview::class, ['termId' => $term->id])
            ->assertSee('Needs your action')
            ->assertSee('Reply by Aug 20, 2026')
            ->assertSee($fixture['course']->code)
            ->assertSee($fixture['section']->code)
            ->assertSee('View schedule')
            ->assertSeeHtml('href="'.e(MyAvailability::getUrl()).'"')
            ->assertSeeHtml('href="'.e(FacultySchedule::getUrl()).'"');

        // 2. Submit availability in chosen windows, then narrow grid -> locked informational note (no self-edit CTA).
        app(RecordFacultyAvailabilityDeclaration::class)->execute(
            term: $term,
            faculty: $faculty,
            actor: $faculty,
            availabilityMode: FacultyAvailabilityDeclaration::ModeWindows,
            teachingWindows: [
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00'],
            ],
        );
        $fixture['package']->teachingGridRows()->where('day_of_week', 1)->update(['ends_at' => '11:00:00']);

        Livewire::actingAs($faculty)
            ->test(FacultyOverview::class, ['termId' => $term->id])
            ->assertSee('Teaching hours changed after submission')
            ->assertSee('Contact the Registrar')
            ->assertSee('View availability')
            ->assertDontSee('Update times')
            ->assertDontSee('Update availability');

        // 3. Returned class grades + open INC completion -> surfaced in action list and compact Class list & grades card.
        $returnedRoster = $this->createRosterForSection($faculty, $fixture['offering'], $fixture['section'], GradeRoster::StateReturned, [
            'return_reason' => 'Verify student 2026-001 final grade.',
        ]);

        $incSection = Section::factory()->for($fixture['offering'], 'termOffering')->create([
            'code' => 'BSIT-3B',
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $incRoster = $this->createRosterForSection($faculty, $fixture['offering'], $incSection, GradeRoster::StateReleased);
        $this->attachOpenIncStudent($incRoster, $faculty, $term);

        Livewire::actingAs($faculty)
            ->test(FacultyOverview::class, ['termId' => $term->id])
            ->assertSeeInOrder(['Fix returned grades', 'Submit INC completion'])
            ->assertSee('Verify student 2026-001 final grade.')
            ->assertSee('2 assigned classes · 1 needing action')
            ->assertSee('1 open INC completion')
            ->assertSeeHtml('href="'.e(FacultyGradeRoster::getUrl(['roster' => $returnedRoster->id])).'"')
            ->assertSeeHtml('href="'.e(FacultyGradeRoster::getUrl(['roster' => $incRoster->id])).'"')
            ->assertSeeHtml('href="'.e(FacultyGradeRoster::getUrl(['queue' => 'needs-action'])).'"');
    }

    #[Test]
    public function faculty_sidebar_navigation_exposes_sentence_case_items_class_list_and_grades_children_and_clean_urls(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $fixture = $this->scheduleFixture($registrar, $faculty);
        $term = $fixture['term'];

        // Create 1 returned roster so actionable badge = 1, and no open INC yet.
        $this->createRosterForSection($faculty, $fixture['offering'], $fixture['section'], GradeRoster::StateReturned);

        $this->actingAs($faculty);
        $request = Request::create('/admin/faculty-overview', 'GET', ['termId' => $term->id]);
        $this->app->instance('request', $request);

        $navItems = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->values();

        $this->assertSame(
            ['Overview', 'My schedule', 'Academic calendar', 'My availability', 'Class list & grades'],
            $navItems->map->getLabel()->all(),
        );

        foreach ($navItems as $item) {
            $this->assertStringNotContainsString('termId=', (string) $item->getUrl());
        }

        /** @var NavigationItem $classGrades */
        $classGrades = $navItems->firstWhere(fn (NavigationItem $item): bool => $item->getLabel() === 'Class list & grades');
        $this->assertSame('1', $classGrades->getBadge());

        $children = collect($classGrades->getChildItems());
        $this->assertSame(['Needs action', 'All classes'], $children->map->getLabel()->all());
        $this->assertSame('1', $children[0]->getBadge());
        $this->assertSame(FacultyGradeRoster::getUrl(['queue' => 'needs-action']), $children[0]->getUrl());
        $this->assertSame(FacultyGradeRoster::getUrl(['queue' => 'all']), $children[1]->getUrl());

        // Now add an open INC completion -> INC follow-up child item appears with badge 1.
        $incSection = Section::factory()->for($fixture['offering'], 'termOffering')->create([
            'code' => 'BSIT-3B',
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $incRoster = $this->createRosterForSection($faculty, $fixture['offering'], $incSection, GradeRoster::StateReleased);
        $this->attachOpenIncStudent($incRoster, $faculty, $term);

        $updatedNavItems = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->values();
        /** @var NavigationItem $updatedClassGrades */
        $updatedClassGrades = $updatedNavItems->firstWhere(fn (NavigationItem $item): bool => $item->getLabel() === 'Class list & grades');
        $updatedChildren = collect($updatedClassGrades->getChildItems());

        $this->assertSame(['Needs action', 'All classes', 'INC follow-up'], $updatedChildren->map->getLabel()->all());
        $this->assertSame('1', $updatedChildren[2]->getBadge());
        $this->assertSame(FacultyGradeRoster::getUrl(['queue' => 'inc']), $updatedChildren[2]->getUrl());

        // Global search uses sentence-case Faculty labels without duplicates.
        $search = app(WorkspaceNavigationSearchProvider::class);
        $this->assertSame(['Overview'], $search->getResults('Overview')->getCategories()->flatten()->pluck('title')->all());
        $this->assertSame(['My schedule'], $search->getResults('My schedule')->getCategories()->flatten()->pluck('title')->all());
        $this->assertSame(['Academic calendar'], $search->getResults('Academic calendar')->getCategories()->flatten()->pluck('title')->all());
        $this->assertSame(['My availability'], $search->getResults('My availability')->getCategories()->flatten()->pluck('title')->all());
        $this->assertSame(['Class list & grades'], $search->getResults('Class list & grades')->getCategories()->flatten()->pluck('title')->all());
    }

    private function staff(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['stored-code']);

        return $user;
    }

    private function activePackage(User $registrar): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->create();
        $package->teachingGridRows()->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
        ]);

        return app(ActivateTermCalendarPackage::class)->execute($package, $registrar);
    }

    /** @return array<string, mixed> */
    private function scheduleFixture(User $registrar, User $faculty): array
    {
        $package = $this->activePackage($registrar);
        $term = $package->term;
        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create(['code' => 'IT 312']);
        $specification = CourseSpecification::factory()->for($course)->create([
            'title' => 'Systems Integration and Architecture 1',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => [TermOffering::ModalityFaceToFace],
        ]);
        $component = CourseComponent::factory()->for($specification)->create();
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => TermOffering::StateScheduled,
            'expected_count' => 30,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'BSIT-3A',
            'capacity' => 30,
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'expected_count' => 30,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionDeliveryGroup::StateReady,
        ]);
        FacultyQualification::factory()->for($faculty, 'faculty')->for($course)->create();
        $demand = SchedulingDemand::factory()->for($offering)->for($component)->for($group)->create([
            'modality' => TermOffering::ModalityFaceToFace,
            'validation_state' => SchedulingDemand::ValidationReadyForReview,
        ]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'requested_by' => $registrar->id,
            'input_snapshot' => ['scheduling_demands' => [['scheduling_demand_id' => $demand->id]]],
            'input_hash' => hash('sha256', 'issue93-overview-'.uniqid()),
            'solver_version' => 'issue93-overview-test',
            'published_by' => $registrar->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        $room = Room::factory()->create();
        $timetable = PublishedTimetableVersion::query()->create([
            'term_id' => $term->id,
            'schedule_run_id' => $run->id,
            'version' => 1,
            'state' => PublishedTimetableVersion::StatePublished,
            'authority_reference' => 'ISSUE93-FACULTY-OVERVIEW',
            'publication_reason' => 'Issue 93 faculty overview fixture.',
            'source_versions' => [],
            'impact_summary' => [],
            'content_hash' => hash('sha256', 'issue93-timetable-'.$run->id),
            'published_by' => $registrar->id,
            'published_at' => now(),
        ]);
        $meeting = SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'published_timetable_version_id' => $timetable->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $faculty->id,
            'room_id' => $room->id,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '11:00:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);

        return compact('registrar', 'faculty', 'package', 'term', 'offering', 'course', 'section', 'meeting', 'timetable', 'run');
    }

    /** @param array<string, mixed> $overrides */
    private function createRosterForSection(User $faculty, TermOffering $offering, Section $section, string $state, array $overrides = []): GradeRoster
    {
        $assignment = ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'assigned_by' => $faculty->id,
            'effective_at' => now(),
            'authority_reference' => 'TEST-ASSIGNMENT-'.uniqid(),
        ]);

        return GradeRoster::factory()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'teaching_assignment_id' => $assignment->id,
            'state' => $state,
            ...$overrides,
        ]);
    }

    private function attachOpenIncStudent(GradeRoster $roster, User $faculty, Term $term): void
    {
        $student = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'term_id' => $term->id,
            'student_profile_id' => $student->id,
            'credential_user_id' => $student->user_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
        ]);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $roster->term_offering_id,
            'section_id' => $roster->section_id,
            'is_current' => true,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => 3.0,
        ]);

        $row = GradeRosterRow::query()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'final_result' => 'INC',
            'inc_completion_note' => 'Final project demo remaining.',
            'current_outcome_code' => 'INC',
            'current_outcome_category' => GradeRosterRow::CategoryIncomplete,
            'released_at' => now(),
            'is_current_membership' => true,
        ]);

        GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => 'INC',
            'new_value' => null,
            'new_category' => GradeRosterRow::CategoryIncomplete,
            'inc_completion_note' => 'Final project demo remaining.',
            'deadline' => now()->addMonths(6)->toDateString(),
            'recorded_by' => $faculty->id,
            'released_at' => now(),
        ]);
    }
}
