<?php

namespace Tests\Feature;

use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\Sections\Pages\CreateSection;
use App\Filament\Resources\Sections\Pages\ListSections;
use App\Filament\Resources\Terms\Pages\CreateTerm;
use App\Models\AcademicYear;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\FacultyQualification;
use App\Models\OperationalEvent;
use App\Models\PublishedTimetableVersion;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue76TermOverviewTasksTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_legacy_classes_keep_recorded_population_and_lead_to_real_student_section_entry(): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        Section::factory()->count(2)->for(TermOffering::factory()->for($term))->create();

        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class);

        $this->assertSame(['recorded' => 2, 'confirmed' => 0, 'current' => 0, 'student_sections' => 0], $page->viewData('overviewCounts'));
        $work = collect($page->viewData('overviewWork'))->keyBy('key');
        $this->assertSame('Add student section', $work['sections']['label']);
        $this->assertSame(TermClasses::getUrl(['termId' => $term->id, 'classesView' => 'cohorts']), $work['sections']['url']);
        $this->assertSame('Review and confirm classes', $work['classes']['label']);
        $this->assertSame(TermClasses::getUrl(['termId' => $term->id]), $work['classes']['url']);
    }

    #[TestWith([true, true])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([false, false])]
    public function test_only_a_delivered_request_for_this_calendar_makes_missing_availability_waiting(bool $delivered, bool $hasDeadline): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now(), 'faculty_availability_due_at' => $hasDeadline ? now()->addWeek() : null]);
        $offering = TermOffering::factory()->for($term)->create();
        $faculty = User::factory()->create();
        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $offering->course()->id]);
        OperationalEvent::factory()->create([
            'event_type' => OperationalEvent::TypeFacultyAvailabilityRequestedEmail,
            'related_record_type' => TermCalendarPackage::class,
            'related_record_id' => $delivered ? $package->id : TermCalendarPackage::factory()->create()->id,
            'user_id' => $faculty->id,
            'status' => OperationalEvent::StatusProcessed,
            'sent_at' => now(),
        ]);

        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class);

        $this->assertSame($delivered, collect($page->viewData('overviewWaiting'))->contains('key', 'availability'));
        $this->assertSame(! $delivered && $hasDeadline, collect($page->viewData('overviewWork'))->contains('key', 'availability'));
        $this->assertSame(! $hasDeadline, collect($page->viewData('overviewWork'))->contains('key', 'deadline'));
    }

    #[TestWith([ScheduleGenerationRun::StatusQueued, null, 'pending'])]
    #[TestWith([ScheduleGenerationRun::StatusFailed, null, 'failed'])]
    #[TestWith([ScheduleGenerationRun::StatusBlocked, null, 'blocked'])]
    #[TestWith([ScheduleGenerationRun::StatusUnderReview, 'Accepted', 'accepted'])]
    #[TestWith([ScheduleGenerationRun::StatusUnderReview, 'Rejected', 'rejected'])]
    #[TestWith([ScheduleGenerationRun::StatusUnderReview, 'Stale', 'stale'])]
    public function test_separate_attempt_preserves_official_result_and_queued_work_is_waiting(string $status, ?string $candidateState, string $expectedState): void
    {
        $this->registrar();
        $term = Term::factory()->create();
        $official = PublishedTimetableVersion::factory()->for($term)->create();
        $run = ScheduleGenerationRun::factory()->for($term)->create(['status' => $status, 'candidate_state' => $candidateState, 'published_at' => null, 'published_by' => null]);

        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class);

        $timetable = $page->viewData('overviewTimetable');
        $this->assertSame('official', $timetable['official']['state']);
        $this->assertSame(TermTimetable::getUrl(['termId' => $term->id, 'selectedVersionId' => $official->id]), $timetable['official']['url']);
        $this->assertSame($expectedState, $timetable['attempt']['state']);
        $this->assertSame(TermTimetable::getUrl(['termId' => $term->id, 'selectedRunId' => $run->id]), $timetable['attempt']['url']);
        $this->assertSame($expectedState === 'pending', collect($page->viewData('overviewWaiting'))->contains('key', 'result'));
        $this->assertSame($expectedState !== 'pending', collect($page->viewData('overviewWork'))->contains('key', 'result'));
        $this->assertFalse(collect($page->viewData('overviewWork'))->contains('key', 'generate'));
    }

    #[TestWith([User::StaffRoleRegistrar])]
    #[TestWith([User::StaffRoleAcademicHead])]
    public function test_change_term_overrides_the_unique_active_default_and_explicit_selection_wins(string $role): void
    {
        $this->registrar($role);
        TermCalendarPackage::query()->where('state', TermCalendarPackage::StateActive)
            ->update(['state' => TermCalendarPackage::StateClosed]);
        $active = TermCalendarPackage::factory()->create(['state' => TermCalendarPackage::StateActive])->term;
        $other = Term::factory()->create();

        Livewire::test(TermOverview::class)->assertSet('termId', $active->id)
            ->assertSee('Opened automatically: the only Term with an active teaching calendar.');
        Livewire::withQueryParams(['chooseTerm' => true])->test(TermOverview::class)
            ->assertSet('termId', null)->assertSee('Choose a Term to plan.')
            ->call('selectTerm', $other->id)
            ->assertRedirect(TermOverview::getUrl(['termId' => $other->id]));
        Livewire::withQueryParams(['termId' => $other->id])->test(TermOverview::class)
            ->assertSet('termId', $other->id)->assertSet('termAutoSelected', false);
    }

    public function test_invalid_term_does_not_fall_back_and_a_task_without_context_returns_to_the_chooser(): void
    {
        $this->registrar();
        auth()->user()->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        TermCalendarPackage::query()->where('state', TermCalendarPackage::StateActive)
            ->update(['state' => TermCalendarPackage::StateClosed]);

        Livewire::withQueryParams(['termId' => (int) Term::query()->max('id') + 1])
            ->test(TermOverview::class)->assertNotFound();
        Livewire::withQueryParams([])->test(TermClasses::class)
            ->assertRedirect(TermOverview::getUrl(['chooseTerm' => true]));
    }

    #[TestWith([Term::TypeFirstSemester, null, 'First Semester'])]
    #[TestWith([Term::TypeSecondSemester, '', 'Second Semester'])]
    #[TestWith([Term::TypeSpecialTerm, null, 'Special Term'])]
    #[TestWith([Term::TypeFirstSemester, 'Approved special calendar', 'Approved special calendar'])]
    public function test_term_creation_derives_a_routine_name_and_retains_an_explicit_custom_name(string $type, ?string $customName, string $expectedName): void
    {
        $this->registrar();
        $year = AcademicYear::factory()->create();

        $page = Livewire::test(CreateTerm::class)->fillForm([
            'academic_year_id' => $year->id, 'label' => $customName, 'type' => $type,
            'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString(),
        ])->call('create')->assertHasNoFormErrors();

        $term = Term::query()->where('academic_year_id', $year->id)->sole();
        $this->assertSame($expectedName, $term->label);
        $this->assertSame(Term::StateDraft, $term->state);
        $page->assertRedirect(TermOverview::getUrl(['termId' => $term->id]));
    }

    public function test_optional_name_keeps_academic_year_term_type_and_dates_required(): void
    {
        $this->registrar();
        $count = Term::query()->count();

        Livewire::test(CreateTerm::class)->fillForm([
            'academic_year_id' => null, 'label' => null, 'type' => null,
            'starts_on' => null, 'ends_on' => null,
        ])->assertSeeHtml('href="'.e(TermOverview::getUrl(['chooseTerm' => true])).'"')
            ->call('create')->assertHasFormErrors([
                'academic_year_id' => 'required', 'type' => 'required',
                'starts_on' => 'required', 'ends_on' => 'required',
            ]);

        $this->assertSame($count, Term::query()->count());
    }

    public function test_retained_class_entries_use_the_canonical_workbench_and_the_same_create_dialog(): void
    {
        $this->registrar();
        auth()->user()->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        $term = Term::factory()->create();

        Livewire::withQueryParams(['termId' => $term->id])->test(ListSections::class)
            ->assertRedirect(TermClasses::getUrl(['termId' => $term->id]));
        Livewire::withQueryParams(['termId' => $term->id])->test(CreateSection::class)
            ->assertRedirect(TermClasses::getUrl(['termId' => $term->id, 'action' => 'addClass']));
        Livewire::withQueryParams(['termId' => $term->id, 'action' => 'addClass'])->test(TermClasses::class)
            ->mountAction('addClass')->assertActionMounted('addClass')->assertMountedActionModalSee('Add draft class')
            ->call('unmountAction')->assertActionNotMounted('addClass');
        $this->assertSame(0, Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))->count());
    }

    public function test_draft_class_dialog_saves_only_in_the_selected_term_without_confirming_or_publishing(): void
    {
        $this->registrar();
        auth()->user()->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        $term = Term::factory()->create();
        $offering = TermOffering::factory()->for($term)->create();
        $other = TermOffering::factory()->create();
        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class);
        $page->callAction('addClass', ['term_offering_id' => $other->id, 'code' => 'WRONG-TERM', 'capacity' => 20])
            ->assertHasActionErrors(['term_offering_id']);
        $this->assertDatabaseMissing('sections', ['code' => 'WRONG-TERM']);

        $page->call('unmountAction')->callAction('addClass', [
            'term_offering_id' => $offering->id, 'code' => 'EXPLICIT-DRAFT', 'capacity' => 20, 'state' => Section::StateOpen,
        ])->assertHasNoActionErrors();
        $class = Section::query()->where('code', 'EXPLICIT-DRAFT')->sole();
        $this->assertSame($offering->id, $class->term_offering_id);
        $this->assertSame(Section::StatePlanned, $class->state);
        $this->assertNull($class->confirmed_at);
        $this->assertSame(0, PublishedTimetableVersion::query()->where('term_id', $term->id)->count());
    }

    public function test_head_cannot_open_the_draft_class_command(): void
    {
        $this->registrar(User::StaffRoleAcademicHead);
        $term = Term::factory()->create();

        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertActionHidden('addClass')->mountAction('addClass')->assertActionNotMounted('addClass');
    }

    public function test_pending_class_command_cannot_switch_term_context(): void
    {
        $this->registrar();
        auth()->user()->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        $term = Term::factory()->create();
        $other = Term::factory()->create();
        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)->mountAction('addClass');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('termId', $other->id);
    }

    #[TestWith([User::StaffRoleRegistrar])]
    #[TestWith([User::StaffRoleAcademicHead])]
    public function test_term_faculty_scope_keeps_respondents_and_excludes_unrelated_active_faculty(string $role): void
    {
        $this->registrar($role);
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $term = Term::factory()->create();
        $offering = TermOffering::factory()->for($term)->create();
        $currentFaculty = User::factory()->create(['name' => 'TERM-QUALIFIED-FACULTY', 'status' => User::StatusActive]);
        $respondedFaculty = User::factory()->create(['name' => 'TERM-RESPONDED-FACULTY', 'status' => User::StatusActive]);
        $unrelatedFaculty = User::factory()->create(['name' => 'UNRELATED-FACULTY', 'status' => User::StatusActive]);
        foreach ([$currentFaculty, $respondedFaculty, $unrelatedFaculty] as $faculty) {
            $faculty->assignRole(User::StaffRoleFaculty);
        }
        FacultyQualification::factory()->create(['faculty_user_id' => $currentFaculty->id, 'course_id' => $offering->course()->id]);
        FacultyQualification::factory()->create(['faculty_user_id' => $respondedFaculty->id]);
        FacultyQualification::factory()->create(['faculty_user_id' => $unrelatedFaculty->id]);
        FacultyAvailabilityDeclaration::factory()->create([
            'term_id' => $term->id,
            'faculty_user_id' => $respondedFaculty->id,
            'declaration' => FacultyAvailabilityDeclaration::DeclarationUnavailable,
        ]);

        $page = Livewire::withQueryParams(['termId' => $term->id, 'resourceView' => 'faculty'])->test(TermResources::class);
        $defaultRows = $page->instance()->getTableRecords()->items();

        $this->assertArrayHasKey('faculty-'.$currentFaculty->id, $defaultRows);
        $this->assertArrayHasKey('faculty-'.$respondedFaculty->id, $defaultRows);
        $this->assertArrayNotHasKey('faculty-'.$unrelatedFaculty->id, $defaultRows);
        $this->assertSame(2, $page->viewData('termFacultyCount'));
        $this->assertSame(1, $page->viewData('facultyResponseCount'));
        $this->assertSame(0, $page->viewData('awaitingRequestedResponses'));
        $this->assertSame(1, $page->viewData('notRequestedResponses'));

        $allRows = $page->filterTable('faculty_scope', 'all')->instance()->getTableRecords()->items();

        $this->assertArrayHasKey('faculty-'.$unrelatedFaculty->id, $allRows);
        $this->assertSame(2, $page->viewData('termFacultyCount'));

        $unfilteredRows = $page->call('removeTableFilter', 'faculty_scope')->instance()->getTableRecords()->items();

        $this->assertArrayHasKey('faculty-'.$unrelatedFaculty->id, $unfilteredRows);
        $this->assertSame(2, $page->viewData('termFacultyCount'));
    }

    public function test_empty_term_faculty_view_explains_setup_and_keeps_catalog_reachable(): void
    {
        $this->registrar();
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $term = Term::factory()->create();
        $faculty = User::factory()->create(['name' => 'AAA-CATALOG-ONLY-FACULTY', 'status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);

        $page = Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertSee('No faculty linked to this Term')
            ->assertSee('View classes');
        $this->assertSame(0, $page->instance()->getTableRecords()->total());

        $rows = $page->filterTable('faculty_scope', 'all')->searchTable($faculty->fresh()->name)->instance()->getTableRecords()->items();
        $this->assertArrayHasKey('faculty-'.$faculty->id, $rows);
    }

    private function registrar(string $role = User::StaffRoleRegistrar): void
    {
        Role::findOrCreate($role, 'web');
        $actor = User::factory()->create();
        $actor->assignRole($role);

        $this->actingAs($actor);
    }
}
