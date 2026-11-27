<?php

namespace Tests\Feature\AcademicScheduling;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Filament\Pages\FacultyAcademicCalendar;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\FacultyOverview;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\MyAvailability;
use App\Models\AcademicYear;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermDatedException;
use App\Models\TermOffering;
use App\Models\User;
use App\Support\FacultyActiveTermResolver;
use App\Support\WorkspaceNavigationSearchProvider;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue102FacultyAcademicCalendarTest extends TestCase
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
    public function only_faculty_context_can_access_academic_calendar(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $dual = $this->staff(User::StaffRoleFaculty);
        $dual->assignRole(User::StaffRoleRegistrar);

        $this->actingAs($faculty)
            ->get(FacultyAcademicCalendar::getUrl())
            ->assertOk();

        $this->actingAs($registrar)
            ->get(FacultyAcademicCalendar::getUrl())
            ->assertForbidden();

        $this->actingAs($dual)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar])
            ->get(FacultyAcademicCalendar::getUrl())
            ->assertForbidden();

        $this->actingAs($dual)
            ->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleFaculty])
            ->get(FacultyAcademicCalendar::getUrl())
            ->assertOk();
    }

    #[Test]
    public function active_term_resolver_follows_deterministic_rollover_order(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $resolver = app(FacultyActiveTermResolver::class);

        $ay = AcademicYear::factory()->create(['label' => 'AY 2026-2027']);

        // 4. No qualifying terms -> null.
        $this->assertNull($resolver->resolve($faculty));
        $this->assertNull($resolver->resolveId($faculty));

        // 3. Assigned non-closed draft term fallback.
        $draftTerm = Term::factory()->for($ay)->create([
            'label' => 'First Semester',
            'state' => Term::StateDraft,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-12-15',
        ]);
        $this->assignFacultyToTerm($faculty, $draftTerm);
        $this->assertSame($draftTerm->id, $resolver->resolveId($faculty));

        // 2. Active-state term without calendar outranks draft assignment fallback.
        $activeStateTerm = Term::factory()->for($ay)->create([
            'label' => 'Second Semester',
            'state' => Term::StateActive,
            'starts_on' => '2027-01-10',
            'ends_on' => '2027-05-20',
        ]);
        $this->assertSame($activeStateTerm->id, $resolver->resolveId($faculty));

        // Closed term with active package must be ignored.
        $closedTerm = Term::factory()->for($ay)->create([
            'label' => 'Closed Term',
            'state' => Term::StateActive,
            'starts_on' => '2028-01-01',
            'ends_on' => '2028-05-01',
        ]);
        $this->createActivatedPackage($registrar, $closedTerm);
        $closedTerm->update(['state' => Term::StateClosed]);
        $this->assertSame($activeStateTerm->id, $resolver->resolveId($faculty));

        // 1a. Non-closed draft term with active calendar outranks active-state term without calendar.
        $this->createActivatedPackage($registrar, $draftTerm);
        $this->assertSame($draftTerm->id, $resolver->resolveId($faculty));

        // 1b. Active-state term with active calendar outranks draft term with active calendar.
        $this->createActivatedPackage($registrar, $activeStateTerm);
        $this->assertSame($activeStateTerm->id, $resolver->resolveId($faculty));

        // Scope title & meta formatting.
        $resolved = $resolver->resolve($faculty);
        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->relationLoaded('academicYear'));
        $this->assertSame('AY 2026-2027 · Second Semester', $resolver->scopeTitle($resolved));
        $this->assertSame('Jan 10 – May 20, 2027 · Term calendar active', $resolver->scopeMeta($resolved, true));
        $this->assertSame('Jan 10 – May 20, 2027 · Term calendar not active', $resolver->scopeMeta($resolved, false));
    }

    #[Test]
    public function academic_calendar_renders_active_term_dates_windows_breaks_and_exceptions_read_only(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $ay = AcademicYear::factory()->create(['label' => 'AY 2026-2027']);
        $term = Term::factory()->for($ay)->create([
            'label' => 'First Semester',
            'state' => Term::StateActive,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-12-15',
        ]);

        $package = TermCalendarPackage::factory()->for($term)->create([
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-15',
            'classes_start_on' => '2026-08-10',
            'classes_end_on' => '2026-12-05',
            'recorded_by' => $registrar->id,
        ]);
        $package->teachingGridRows()->create([
            'day_of_week' => 1,
            'starts_at' => '07:30:00',
            'ends_at' => '18:00:00',
            'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
        ]);

        $nowManila = CarbonImmutable::now('Asia/Manila');

        TermCalendarWindow::query()->create([
            'term_calendar_package_id' => $package->id,
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => $nowManila->subDays(10)->toDateString(),
            'closes_on' => $nowManila->subDays(3)->toDateString(),
            'cutoff_at' => '17:00:00',
        ]);
        TermCalendarWindow::query()->create([
            'term_calendar_package_id' => $package->id,
            'window_type' => TermCalendarWindow::TypeExaminationPeriod,
            'opens_on' => $nowManila->subDay()->toDateString(),
            'closes_on' => $nowManila->addDays(4)->toDateString(),
            'cutoff_at' => '18:00:00',
        ]);
        TermCalendarWindow::query()->create([
            'term_calendar_package_id' => $package->id,
            'window_type' => TermCalendarWindow::TypeGradeEntry,
            'opens_on' => $nowManila->addDays(5)->toDateString(),
            'closes_on' => $nowManila->addDays(12)->toDateString(),
            'cutoff_at' => '23:59:00',
        ]);

        TermDatedException::query()->create([
            'term_calendar_package_id' => $package->id,
            'starts_on' => $nowManila->addDays(3)->toDateString(),
            'ends_on' => $nowManila->addDays(3)->toDateString(),
            'exception_type' => 'Holiday',
            'label' => 'Foundation Day',
            'blocks_teaching' => true,
        ]);
        TermDatedException::query()->create([
            'term_calendar_package_id' => $package->id,
            'starts_on' => $nowManila->addDays(7)->toDateString(),
            'ends_on' => $nowManila->addDays(7)->toDateString(),
            'exception_type' => 'School Event',
            'label' => 'Research Colloquium',
            'blocks_teaching' => false,
        ]);

        app(ActivateTermCalendarPackage::class)->execute($package, $registrar);

        $component = Livewire::actingAs($faculty)
            ->test(FacultyAcademicCalendar::class)
            ->assertSet('termId', $term->id)
            ->assertSee('AY 2026-2027 · First Semester')
            ->assertSee('Aug 10 – Dec 5, 2026 · Term calendar active')
            ->assertSee('Teaching period & weekly hours')
            ->assertSee('Term dates:')
            ->assertSee('Mon · 7:30 AM–6:00 PM')
            ->assertSee('12:00 PM–1:00 PM')
            ->assertSee('Academic windows')
            ->assertSee('Official grades are released by the Registrar after review.')
            ->assertSee('Closed')
            ->assertSee('Open')
            ->assertSee('Upcoming')
            ->assertSee('Not set')
            ->assertSee('Asia/Manila')
            ->assertSee('Foundation Day')
            ->assertSee('No classes')
            ->assertSee('Research Colloquium')
            ->assertSee('Classes continue')
            ->assertSeeHtml('<time datetime="2026-08-10">')
            ->assertSeeHtml('tabular-nums')
            ->assertDontSee('Choose a term');

        $visibleText = preg_replace('/href="[^"]*"/i', '', $component->html());
        $this->assertStringNotContainsStringIgnoringCase('roster', strip_tags((string) $visibleText));
    }

    #[Test]
    public function academic_calendar_renders_empty_states_and_guards_invalid_term_id(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);

        Livewire::actingAs($faculty)
            ->test(FacultyAcademicCalendar::class)
            ->assertSet('termId', null)
            ->assertSee('No active term yet');

        $termWithoutPackage = Term::factory()->create([
            'state' => Term::StateActive,
            'label' => 'Unconfigured Term',
        ]);

        Livewire::actingAs($faculty)
            ->test(FacultyAcademicCalendar::class)
            ->assertSet('termId', $termWithoutPackage->id)
            ->assertSee('Term calendar is not active yet');

        $this->actingAs($faculty)
            ->get(FacultyAcademicCalendar::getUrl(['termId' => 999999]))
            ->assertNotFound();
    }

    #[Test]
    public function faculty_sidebar_navigation_and_search_include_academic_calendar_and_clean_urls(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $this->createActivatedPackage($registrar, $term);

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
            /** @var NavigationItem $item */
            $this->assertStringNotContainsString('termId=', (string) $item->getUrl());
        }

        /** @var NavigationItem $calendarItem */
        $calendarItem = $navItems->firstWhere(fn (NavigationItem $item): bool => $item->getLabel() === 'Academic calendar');
        $this->assertSame(FacultyAcademicCalendar::getUrl(), $calendarItem->getUrl());

        /** @var NavigationItem $overviewItem */
        $overviewItem = $navItems->firstWhere(fn (NavigationItem $item): bool => $item->getLabel() === 'Overview');
        $this->assertSame(FacultyOverview::getUrl(), $overviewItem->getUrl());

        /** @var NavigationItem $scheduleItem */
        $scheduleItem = $navItems->firstWhere(fn (NavigationItem $item): bool => $item->getLabel() === 'My schedule');
        $this->assertSame(FacultySchedule::getUrl(), $scheduleItem->getUrl());

        /** @var NavigationItem $availabilityItem */
        $availabilityItem = $navItems->firstWhere(fn (NavigationItem $item): bool => $item->getLabel() === 'My availability');
        $this->assertSame(MyAvailability::getUrl(), $availabilityItem->getUrl());

        /** @var NavigationItem $gradesItem */
        $gradesItem = $navItems->firstWhere(fn (NavigationItem $item): bool => $item->getLabel() === 'Class list & grades');
        $this->assertSame(FacultyGradeRoster::getUrl(), $gradesItem->getUrl());

        $search = app(WorkspaceNavigationSearchProvider::class);
        $this->assertSame(['Academic calendar'], $search->getResults('Academic calendar')->getCategories()->flatten()->pluck('title')->all());
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

    private function createActivatedPackage(User $registrar, Term $term): TermCalendarPackage
    {
        $package = TermCalendarPackage::factory()->for($term)->create([
            'administrative_starts_on' => $term->starts_on?->toDateString(),
            'administrative_ends_on' => $term->ends_on?->toDateString(),
            'classes_start_on' => $term->starts_on?->toDateString(),
            'classes_end_on' => $term->ends_on?->toDateString(),
            'recorded_by' => $registrar->id,
        ]);
        $package->teachingGridRows()->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
        ]);

        return app(ActivateTermCalendarPackage::class)->execute($package, $registrar);
    }

    private function assignFacultyToTerm(User $faculty, Term $term): void
    {
        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->for($program)->create(['state' => CurriculumVersion::StateActive]);
        $course = Course::factory()->create();
        $specification = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        $entry = CurriculumEntry::factory()->for($curriculum)->for($specification, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()->for($offering, 'termOffering')->create(['state' => Section::StateOpen]);

        ClassOfferingTeachingAssignment::query()->create([
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'faculty_user_id' => $faculty->id,
            'role' => ClassOfferingTeachingAssignment::RoleDesignated,
            'state' => ClassOfferingTeachingAssignment::StateActive,
            'assigned_by' => $faculty->id,
            'effective_at' => now(),
            'authority_reference' => 'TEST-ISSUE102-'.uniqid(),
        ]);
    }
}
