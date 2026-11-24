<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Models\AcademicYear;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Database\Seeders\Issue58TimetableExplorationSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TermPlanningWorkflowComprehensionTest extends TestCase
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

    #[TestWith([User::StaffRoleRegistrar, 0])]
    #[TestWith([User::StaffRoleRegistrar, 2])]
    #[TestWith([User::StaffRoleAcademicHead, 0])]
    #[TestWith([User::StaffRoleAcademicHead, 2])]
    public function test_term_planning_shows_chooser_without_one_active_term(string $role, int $activeTerms): void
    {
        Role::findOrCreate($role, 'web');
        $actor = User::factory()->create();
        $actor->assignRole($role);
        $actor->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $actor->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();
        $this->actingAs($actor)->withSession([WorkspaceContextResolver::SessionKey => $role]);
        TermCalendarPackage::query()->where('state', TermCalendarPackage::StateActive)
            ->update(['state' => TermCalendarPackage::StateClosed]);
        $targetYear = AcademicYear::factory()->create(['label' => 'AY 2033-2034']);
        $term = Term::factory()->for($targetYear)->create(['label' => 'Exact selected Term']);
        foreach (Term::factory()->count($activeTerms)->create() as $activeTerm) {
            TermCalendarPackage::factory()->for($activeTerm)->create(['state' => TermCalendarPackage::StateActive]);
        }

        $directoryResponse = $this->get(TermOverview::getUrl())->assertOk()->assertSee('Exact selected Term');
        if ($role === User::StaffRoleRegistrar) {
            $directoryResponse->assertSee('New term');
        } else {
            $directoryResponse->assertSee('Academic Oversight')->assertDontSee('New term');
        }

        $calendarResponse = $this->get(TermCalendar::getUrl(['termId' => $term->id]))
            ->assertOk()
            ->assertSee('id="term-context-switcher"', false)
            ->assertSee('AY 2033-2034');
        if ($role === User::StaffRoleRegistrar) {
            $calendarResponse->assertSee('Term settings')->assertDontSee('New term');
        } else {
            $calendarResponse->assertDontSee('New term');
        }

        Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class)
            ->assertRedirect(TermCalendar::getUrl(['termId' => $term->id]));

        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->call('selectTerm', $term->id)
            ->assertRedirect(TermCalendar::getUrl(['termId' => $term->id]));
    }

    #[TestWith([User::StaffRoleFaculty, MyAvailability::class])]
    #[TestWith([User::StaffRoleFaculty, FacultySchedule::class])]
    #[TestWith([User::StaffRoleAcademicHead, AcademicApprovals::class])]
    public function test_invalid_term_links_never_open_another_terms_role_journey(string $role, string $page): void
    {
        Role::findOrCreate($role, 'web');
        $actor = User::factory()->create();
        $actor->assignRole($role);
        $this->actingAs($actor)->withSession([WorkspaceContextResolver::SessionKey => $role]);
        TermCalendarPackage::query()->where('state', TermCalendarPackage::StateActive)
            ->update(['state' => TermCalendarPackage::StateClosed]);
        TermCalendarPackage::factory()->create(['state' => TermCalendarPackage::StateActive]);
        $invalidTermId = (int) Term::query()->max('id') + 1;

        Livewire::withQueryParams(['termId' => $invalidTermId])->test($page)->assertNotFound();
    }

    public function test_term_chooser_groups_and_exposes_every_term_when_there_are_more_than_six(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create();
        $registrar->assignRole(User::StaffRoleRegistrar);
        $registrar->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $registrar->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();
        $this->actingAs($registrar)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleRegistrar]);

        $recentYear = AcademicYear::factory()->create(['label' => '2026-2027']);
        $priorYear = AcademicYear::factory()->create(['label' => '2025-2026']);
        $terms = collect();
        for ($number = 1; $number <= 7; $number++) {
            $isRecent = $number <= 4;
            $terms->push(Term::factory()->for($isRecent ? $recentYear : $priorYear)->create([
                'label' => 'Term '.$number,
                'type' => $number % 2 === 0 ? Term::TypeSecondSemester : Term::TypeFirstSemester,
                'state' => $number === 1 ? Term::StateActive : Term::StateDraft,
                'starts_on' => $isRecent ? '2026-06-01' : '2025-06-01',
                'ends_on' => $isRecent ? '2026-12-31' : '2025-12-31',
            ]));
        }

        Livewire::test(TermOverview::class)
            ->assertOk()
            ->assertSee($recentYear->label)
            ->assertSee($priorYear->label)
            ->assertSee('Active')
            ->assertSee('Draft')
            ->assertSee('Term 1')
            ->assertSee('Term 7')
            ->assertSeeHtml('href="'.e(TermCalendar::getUrl(['termId' => $terms->last()->id])).'"');

        $calendar = Livewire::withQueryParams(['termId' => $terms->first()->id])->test(TermCalendar::class);
        $options = $calendar->instance()->termSwitcherOptions();

        foreach ($terms as $term) {
            $this->assertArrayHasKey($term->id, $options);
        }

        $calendar->assertSee($recentYear->label)
            ->assertSee('Jun 1')
            ->assertSee('Dec 31, 2026')
            ->assertSeeHtml('id="term-context-switcher"')
            ->call('selectTerm', $terms->last()->id)
            ->assertRedirect(TermCalendar::getUrl(['termId' => $terms->last()->id]));
    }

    public function test_empty_term_chooser_keeps_academic_head_read_only_and_points_to_registrar(): void
    {
        Role::findOrCreate(User::StaffRoleAcademicHead, 'web');
        $head = User::factory()->create();
        $head->assignRole(User::StaffRoleAcademicHead);
        $head->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $head->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();
        $this->actingAs($head)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleAcademicHead]);

        Livewire::test(TermOverview::class)
            ->assertSee('No terms recorded')
            ->assertSee('Ask the Registrar to create a term before planning begins.')
            ->assertSee('Academic Oversight')
            ->assertSee(AcademicApprovals::getUrl(), false)
            ->assertDontSee('New term');
    }

    public function test_oversight_requires_exact_term_selection_when_active_terms_overlap(): void
    {
        $head = User::factory()->create();
        Role::findOrCreate(User::StaffRoleAcademicHead, 'web');
        $head->assignRole(User::StaffRoleAcademicHead);
        $this->actingAs($head);
        $terms = Term::factory()->count(2)->create();
        foreach ($terms as $term) {
            TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive]);
        }

        Livewire::test(AcademicApprovals::class)->assertSet('termId', null);
        Livewire::withQueryParams(['termId' => $terms->last()->id])->test(AcademicApprovals::class)
            ->assertSet('termId', $terms->last()->id);
    }

    #[TestWith([FacultySchedule::class, MyAvailability::class])]
    #[TestWith([MyAvailability::class, FacultySchedule::class])]
    public function test_faculty_sidebar_switch_keeps_the_selected_term(string $from, string $to): void
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);
        $faculty->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $faculty->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();
        $term = Term::factory()->create();
        $this->actingAs($faculty)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleFaculty]);

        $this->get($from::getUrl(['termId' => $term->id]))
            ->assertSeeHtml('href="'.e($to::getUrl(['termId' => $term->id])).'"');
    }

    #[TestWith([FacultySchedule::class, MyAvailability::class])]
    #[TestWith([MyAvailability::class, FacultySchedule::class])]
    public function test_faculty_sidebar_keeps_an_automatically_selected_term(string $from, string $to): void
    {
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $faculty = User::factory()->create(['status' => User::StatusActive]);
        $faculty->assignRole(User::StaffRoleFaculty);
        $faculty->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $faculty->forceFill(['two_factor_recovery_codes_acknowledged_at' => now()])->save();
        $term = Term::factory()->create();
        TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive]);
        $this->actingAs($faculty)->withSession([WorkspaceContextResolver::SessionKey => User::StaffRoleFaculty]);

        $this->get($from::getUrl())
            ->assertOk()
            ->assertSeeHtml('href="'.e($to::getUrl(['termId' => $term->id])).'"');
    }

    public function test_plan_exposes_actual_work_and_keeps_candidate_and_official_views_consistent(): void
    {
        [$registrar, $term, $prefix] = $this->scenario();
        $this->actingAs($registrar);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermOverview::class)
            ->assertRedirect(TermCalendar::getUrl(['termId' => $term->id]));

        $projection = app(TermCoverageProjection::class)->forTerm($term);
        $this->assertSame(3, $projection['summary']['class_count']);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->assertSee('Term calendar')
            ->assertSee('Classes run');
        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertSee('24 students')->toggleAllTableColumns()->assertSee($prefix.'-MAIN-CLASS1');
        Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertSee('Synthetic Faculty Alex')
            ->toggleAllTableColumns()
            ->assertSee('Responded ·');
        Livewire::withQueryParams(['termId' => $term->id])->test(TermTimetable::class)
            ->assertSet('timetableFormat', 'week')
            ->assertSee('Whole Term')->assertSee('View weekly timetable')->assertSee('Not measured')
            ->assertDontSee('0 Collisions')->assertDontSee('F A C E T O F A C E');
        Livewire::withQueryParams(['termId' => $term->id, 'timetableState' => 'official'])->test(TermTimetable::class)
            ->assertSet('timetableFormat', 'week')
            ->assertSee('Whole Term')->assertSee('View weekly timetable')->assertSee('Print whole term')
            ->call('showTimetableFormat', 'week')->assertSet('timetableFormat', 'week')->assertSee('View meeting list');
    }

    public function test_overview_groups_all_outstanding_work_with_each_planning_area(): void
    {
        [$registrar, $term] = $this->scenario();
        $this->actingAs($registrar);
        TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)
            ->update(['state' => TermCalendarPackage::StateClosed]);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->assertSeeInOrder(['1. Calendar', '2. Classes & sections', '3. Faculty & rooms', '4. Timetable']);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermTimetable::class)
            ->assertSee('Official timetable');
    }

    public function test_teaching_week_uses_recorded_hours_and_multiple_breaks_without_inventing_days(): void
    {
        $package = new TermCalendarPackage;
        $package->setRelation('teachingGridRows', collect([
            new TermTeachingGridRow(['day_of_week' => 3, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00', 'breaks' => [
                ['starts_at' => '10:30:00', 'ends_at' => '11:00:00'],
                ['starts_at' => '09:30:00', 'ends_at' => '10:00:00'],
            ]]),
            new TermTeachingGridRow(['day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00', 'breaks' => [
                ['starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ]]),
        ]));

        $week = (new TermCalendar)->teachingWeekRows($package);

        $this->assertSame(['Monday', 'Wednesday'], array_column($week, 'day'));
        $this->assertSame(['8:00 AM–12:00 PM', '12:00 PM–1:00 PM', '1:00 PM–5:00 PM'], array_column($week[0]['segments'], 'time'));
        $this->assertEqualsWithDelta(100 / 9, $week[0]['segments'][1]['width'], .001);
        $this->assertSame(['Teaching hours', 'Break', 'Teaching hours', 'Break', 'Teaching hours'], array_column($week[1]['segments'], 'label'));
        $this->assertSame('11:00 AM–12:00 PM', $week[1]['segments'][4]['time']);
        $this->assertEqualsWithDelta(100, array_sum(array_column($week[1]['segments'], 'width')), .003);
    }

    public function test_teaching_week_leaves_an_empty_calendar_empty(): void
    {
        $package = new TermCalendarPackage;
        $package->setRelation('teachingGridRows', collect());

        $this->assertSame([], (new TermCalendar)->teachingWeekRows($package));
    }

    public function test_generation_recovery_uses_the_failed_source_even_with_an_unrelated_calendar_draft(): void
    {
        [$registrar, $term] = $this->scenario();
        $this->actingAs($registrar);
        Room::query()->update(['is_active' => false]);
        TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateDraft, 'version' => 99]);

        $calendar = Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class);
        $readiness = $calendar->instance()->generationReadiness();

        $this->assertSame('resources', $readiness['tab']);
        $this->assertSame('Faculty & rooms', $readiness['blockers'][0]['label']);
        $calendar->assertSee(TermResources::getUrl(['termId' => $term->id]), false);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermTimetable::class)
            ->assertSee(TermResources::getUrl(['termId' => $term->id]), false)
            ->assertSee('Faculty & rooms');
    }

    public function test_activity_amendment_copies_core_and_history_even_if_client_supplies_changed_core(): void
    {
        [$registrar, $term] = $this->scenario();
        $this->actingAs($registrar);
        $active = $term->calendarPackages()->where('state', TermCalendarPackage::StateActive)->sole();
        $signature = $active->coreSignature();
        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->mountAction('addOperationalDates')
            ->setActionData([
                'authority_reference' => 'SYNTHETIC-DATE-AMENDMENT', 'authority_date' => '2026-07-20',
                'classes_start_on' => '2030-01-01', 'teaching_grid_rows' => [],
                'windows' => [['window_type' => 'Enrollment', 'opens_on' => '2026-07-20', 'closes_on' => '2026-07-25', 'cutoff_at' => null]],
            ])->callMountedAction()->assertHasNoActionErrors();
        $updated = $term->calendarPackages()->where('state', TermCalendarPackage::StateActive)->sole();
        $this->assertNotSame($active->id, $updated->id);
        $this->assertSame(0, $term->calendarPackages()->where('state', TermCalendarPackage::StateDraft)->count());
        $this->assertSame($signature, $updated->coreSignature());
        $this->assertSame(TermCalendarPackage::StateClosed, $active->fresh()->state);
        $this->assertSame($active->authority_reference, $updated->authority_reference);
        $this->assertSame('2026-07-20', $updated->windows()->sole()->opens_on->toDateString());
    }

    public function test_activity_amendment_rejects_a_changed_active_source(): void
    {
        [$registrar, $term] = $this->scenario();
        $this->actingAs($registrar);
        $component = Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->mountAction('addOperationalDates');
        $active = $term->calendarPackages()->where('state', TermCalendarPackage::StateActive)->sole();
        $active->update(['authority_reference' => 'SYNTHETIC-CONCURRENT-CHANGE']);
        $component->callMountedAction()->assertHasActionErrors(['windows'])->assertNotified('Calendar source changed');
        $this->assertSame(0, $term->calendarPackages()->where('state', TermCalendarPackage::StateDraft)->count());
    }

    public function test_resources_do_not_use_another_terms_faculty_response(): void
    {
        [$registrar, $term] = $this->scenario();
        $this->actingAs($registrar);
        FacultyAvailabilityDeclaration::query()->where('term_id', $term->id)->delete();
        Livewire::withQueryParams(['termId' => $term->id])->test(TermResources::class)
            ->assertSee('No response')->assertSee('Not sent')->assertDontSee('Responded ·');
        $head = User::factory()->create();
        Role::findOrCreate(User::StaffRoleAcademicHead, 'web');
        $head->assignRole(User::StaffRoleAcademicHead);
        $this->actingAs($head);
        Livewire::withQueryParams(['termId' => $term->id])->test(TermCalendar::class)
            ->assertActionHidden('addOperationalDates');
    }

    public function test_cancelled_confirmed_classes_do_not_hide_unfinished_class_work(): void
    {
        [$registrar, $term] = $this->scenario();
        $this->actingAs($registrar);
        PublishedTimetableVersion::query()
            ->where('term_id', $term->id)
            ->update(['state' => PublishedTimetableVersion::StateSuperseded]);
        $offering = TermOffering::query()->where('term_id', $term->id)->firstOrFail();
        $confirmed = $offering->sections()->firstOrFail();
        $confirmed->update(['state' => Section::StateCancelled]);
        $unprepared = Section::factory()->for($offering, 'termOffering')->create([
            'code' => 'SYNTHETIC-UNPREPARED-'.$term->id, 'state' => Section::StatePlanned,
            'term_calendar_package_id' => null, 'confirmed_at' => null,
        ]);
        $projection = app(TermCoverageProjection::class)->forTerm($term);
        $this->assertSame(3, $projection['summary']['class_count']);

        Livewire::withQueryParams(['termId' => $term->id])->test(TermClasses::class)
            ->assertSee('Needs active calendar source')->mountTableAction('prepareClassOffering', $unprepared)
            ->assertActionDataSet(['section_id' => $unprepared->id]);
    }

    public function test_pending_generation_refreshes_and_does_not_show_failure_recovery(): void
    {
        Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $registrar = User::factory()->create();
        $registrar->assignRole(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive, 'activated_at' => now(),
        ]);
        $run = ScheduleGenerationRun::factory()->for($term)->create([
            'requested_by' => $registrar->id, 'status' => ScheduleGenerationRun::StatusQueued,
            'candidate_state' => null, 'diagnostics' => [], 'published_by' => null,
            'published_at' => null, 'publication_version' => null,
        ]);
        $this->actingAs($registrar);
        $component = Livewire::withQueryParams(['termId' => $term->id])->test(TermTimetable::class)
            ->assertSee('Waiting for the timetable worker')->assertSeeHtml('wire:poll.5s.visible')
            ->assertDontSee('No meetings are available from this draft')
            ->assertActionHidden('retrySolverRun')->assertActionHidden('acceptCandidate')
            ->assertActionHidden('publishOfficialTimetable');

        $run->update(['status' => ScheduleGenerationRun::StatusDispatching]);
        $component->call('$refresh')->assertSee('Scheduling request in progress')
            ->assertSeeHtml('wire:poll.5s.visible')
            ->assertDontSee('No meetings are available from this draft')
            ->assertActionHidden('retrySolverRun');

        $run->update([
            'status' => ScheduleGenerationRun::StatusFailed,
            'diagnostics' => ['solver_dispatch' => [
                'status' => 'failed', 'failure' => [
                    'code' => 'SYNTHETIC_TRANSPORT_FAILURE',
                    'message' => 'Synthetic transport failure; no solver was dispatched.',
                    'retryable' => true, 'final' => true,
                ],
            ]],
        ]);
        $component->call('$refresh')->assertDontSee('Generating this Term')
            ->assertDontSeeHtml('wire:poll.5s.visible')->assertActionHidden('acceptCandidate')
            ->assertActionHidden('publishOfficialTimetable')->assertActionVisible('retrySolverRun')
            ->assertSee('Synthetic transport failure; no solver was dispatched.')
            ->assertSee('No meetings are available from this draft')
            ->assertSee('Open audit record');
        $component->assertSeeHtml('href="'.e($component->instance()->timetableReviewUrl($run->id)).'"');
        $this->assertSame(ScheduleGenerationRun::StatusFailed, $run->fresh()->status);
        $this->assertSame(0, $run->candidateRows()->count());
    }

    /** @return array{User, Term, string} */
    private function scenario(): array
    {
        $role = Role::findOrCreate(User::StaffRoleRegistrar, 'web');
        $role->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate(User::StaffRoleFaculty, 'web');
        $registrar = User::factory()->create();
        $registrar->assignRole(User::StaffRoleRegistrar);
        $prefix = 'SYNTH58-WORKFLOW-'.$registrar->id;
        $ids = app(Issue58TimetableExplorationSeeder::class)->ensure($registrar, $prefix);

        return [$registrar, Term::query()->findOrFail($ids['populated']), $prefix];
    }
}
