<?php

namespace Tests\Feature;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Calendar\TermCalendarPackageReadinessService;
use App\Actions\Scheduling\FacultyAvailabilityRequestService;
use App\Actions\Scheduling\RecordFacultyAvailabilityDeadline;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\GradeRosters\Pages\ListGradeRosters;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ListStudentLifecycleChanges;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\Terms\Pages\CreateTerm;
use App\Filament\Resources\Terms\Pages\EditTerm;
use App\Filament\Resources\Terms\Pages\ViewTerm;
use App\Filament\Resources\Terms\TermResource;
use App\Models\AcademicYear;
use App\Models\CurriculumVersion;
use App\Models\FacultyAvailabilityDeadline;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class Issue58ExactTermPreparationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertSame('test_tala_db', DB::selectOne('SELECT DATABASE() AS active_database')->active_database);
        Filament::setCurrentPanel(Filament::getPanel('staff'));
        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_core_only_calendar_activates_and_missing_dates_block_only_their_consumers(): void
    {
        $actor = $this->staff();
        $package = $this->core();
        app(ActivateTermCalendarPackage::class)->execute($package, $actor);

        $this->assertSame(Term::StateActive, $package->term->fresh()->state);
        Livewire::actingAs($actor)->test(TermCalendar::class, ['termId' => $package->term_id])
            ->assertSee('Calendar active')->assertSee('Not configured')
            ->assertSee('Grade Entry')->assertSee('Sections & classes')
            ->assertViewHas('coreReadiness', fn (array $readiness): bool => $readiness['ready']);
    }

    public function test_entered_dates_need_internal_order_but_approved_consumer_dates_can_surround_instruction(): void
    {
        $package = $this->core();
        $window = TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeGradeEntry, 'opens_on' => '2027-01-02', 'closes_on' => '2027-01-10',
        ]);
        $this->assertTrue(app(TermCalendarPackageReadinessService::class)->for($package)['ready']);
        $window->update(['closes_on' => '2027-01-01']);
        $this->assertFalse(app(TermCalendarPackageReadinessService::class)->for($package->fresh())['ready']);
    }

    public function test_special_term_requires_its_approved_particular_basis(): void
    {
        $package = $this->core();
        $package->term->update(['type' => Term::TypeSpecialTerm]);
        $this->assertFalse(app(TermCalendarPackageReadinessService::class)->for($package->fresh())['ready']);
        $package->update(['special_term_schedule_basis' => 'Approved special class-day and class-hour schedule']);
        $this->assertTrue(app(TermCalendarPackageReadinessService::class)->for($package->fresh())['ready']);
    }

    public function test_stale_activation_rejects_changed_grid_without_any_state_mutation(): void
    {
        $actor = $this->staff();
        $package = $this->core();
        $token = $package->concurrencyToken();
        $package->teachingGridRows()->update(['ends_at' => '11:00']);
        try {
            app(ActivateTermCalendarPackage::class)->execute($package, $actor, $token);
            $this->fail('Stale source must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('source changed', $exception->errors()['package_id'][0]);
        }
        $this->assertSame(TermCalendarPackage::StateDraft, $package->fresh()->state);
        $this->assertSame(Term::StateDraft, $package->term->fresh()->state);
    }

    public function test_successor_prefills_core_and_retains_prior_history_and_active_term(): void
    {
        $actor = $this->staff();
        $package = $this->core();
        app(ActivateTermCalendarPackage::class)->execute($package, $actor);
        $core = $package->fresh()->coreSignature();
        Livewire::actingAs($actor)->test(TermCalendar::class, ['termId' => $package->term_id])
            ->mountAction('recordCalendarPackage')->callMountedAction()->assertHasNoActionErrors();
        $successor = $package->term->calendarPackages()->latest('version')->first();
        $this->assertSame(2, $successor->version);
        $this->assertSame($core, $successor->coreSignature());
        TermCalendarWindow::factory()->for($successor, 'package')->create(['window_type' => TermCalendarWindow::TypeEnrollment]);
        app(ActivateTermCalendarPackage::class)->execute($successor, $actor);
        $this->assertSame(TermCalendarPackage::StateClosed, $package->fresh()->state);
        $this->assertSame(Term::StateActive, $package->term->fresh()->state);
        $this->assertSame($core, $package->fresh()->coreSignature());
    }

    public function test_deadline_after_activation_is_shared_by_requests_and_faculty_without_mutating_core(): void
    {
        Mail::fake();
        $actor = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = app(ActivateTermCalendarPackage::class)->execute($this->core(), $actor);
        $core = $package->concurrencyToken();
        $deadline = app(RecordFacultyAvailabilityDeadline::class)->execute($package->term, $actor, '2026-10-12 09:00:00', 'DEADLINE-58', null, 0);
        $events = app(FacultyAvailabilityRequestService::class)->request($package, [$faculty->id], $actor);
        $this->assertSame($deadline->due_at->toIso8601String(), $events->first()->payload['due_at']);
        $this->assertSame($deadline->id, $events->first()->payload['deadline_source_id']);
        $this->assertSame($core, $package->fresh()->concurrencyToken());
        Livewire::actingAs($faculty)->test(MyAvailability::class, ['termId' => $package->term_id])->assertSee('Oct 12, 2026');
    }

    public function test_deadline_correction_requires_reason_rejects_stale_input_and_retains_records(): void
    {
        $actor = $this->staff();
        $package = app(ActivateTermCalendarPackage::class)->execute($this->core(), $actor);
        $action = app(RecordFacultyAvailabilityDeadline::class);
        $first = $action->execute($package->term, $actor, '2026-10-12 09:00:00', 'D1', null, 0);
        try {
            $action->execute($package->term, $actor, '2026-10-13 09:00:00', 'D2', null, $first->id);
            $this->fail('Correction needs a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('correction_reason', $exception->errors());
        }
        $action->execute($package->term, $actor, '2026-10-13 09:00:00', 'D2', 'Approved correction', $first->id);
        try {
            $action->execute($package->term, $actor, '2026-10-14 09:00:00', 'D3', 'Stale attempt', $first->id);
            $this->fail('Stale deadline must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('due_at', $exception->errors());
        }
        $this->assertSame(2, FacultyAvailabilityDeadline::query()->where('term_id', $package->term_id)->count());
        $this->assertModelExists($first);
    }

    public function test_term_creation_and_edit_cannot_bypass_calendar_activation(): void
    {
        $actor = $this->staff();
        $year = AcademicYear::factory()->create();
        Livewire::actingAs($actor)->test(CreateTerm::class)->fillForm([
            'academic_year_id' => $year->id, 'label' => 'Issue58 new Term', 'type' => Term::TypeFirstSemester,
            'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString(), 'state' => Term::StateActive,
        ])->call('create')->assertHasNoFormErrors();
        $term = Term::query()->where('label', 'Issue58 new Term')->sole();
        $this->assertSame(Term::StateDraft, $term->state);
        Livewire::actingAs($actor)->test(EditTerm::class, ['record' => $term->id])->fillForm(['state' => Term::StateActive])->call('save')->assertHasNoFormErrors();
        $this->assertSame(Term::StateDraft, $term->fresh()->state);
    }

    public function test_term_view_renders_populated_and_empty_historical_days(): void
    {
        $actor = $this->staff();
        $term = Term::factory()->create(['scheduling_days' => [1, 3]]);
        Livewire::actingAs($actor)->test(ViewTerm::class, ['record' => $term->id])->assertSee('Mon, Wed')->assertSee('Return to Term Planning');
        $term->update(['scheduling_days' => null]);
        Livewire::actingAs($actor)->test(ViewTerm::class, ['record' => $term->id])->assertSee('Approved grid is recorded in Term Planning');
    }

    public function test_term_record_drill_in_and_cancellation_retain_the_owning_task(): void
    {
        $actor = $this->staff();
        $term = Term::factory()->create();
        $returnUrl = TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'resources', 'resourceView' => 'faculty']);
        Livewire::actingAs($actor)->test(ViewTerm::class, ['record' => $term->id, 'viewTab' => 'resources'])
            ->assertActionHasUrl('returnToPlanning', $returnUrl)
            ->assertActionHasUrl('edit', TermResource::getUrl('edit', ['record' => $term, 'viewTab' => 'resources', 'resourceView' => 'faculty']));
        $edit = Livewire::actingAs($actor)->test(EditTerm::class, ['record' => $term->id, 'viewTab' => 'resources'])
            ->assertActionHasUrl('returnToPlanning', $returnUrl);
        $create = Livewire::actingAs($actor)->test(CreateTerm::class, ['termId' => $term->id, 'viewTab' => 'resources']);
        foreach ([$edit, $create] as $page) {
            $document = new \DOMDocument;
            @$document->loadHTML($page->html());
            $cancel = collect(iterator_to_array($document->getElementsByTagName('a')))
                ->first(fn (\DOMElement $link): bool => trim($link->textContent) === 'Cancel and return to Term Planning');
            $this->assertNotNull($cancel);
            $this->assertSame($returnUrl, $cancel->getAttribute('href'));
        }
    }

    public function test_activated_term_identity_cannot_be_changed_beneath_its_core_history(): void
    {
        $actor = $this->staff();
        $package = app(ActivateTermCalendarPackage::class)->execute($this->core(), $actor);
        $identity = collect($package->term->fresh()->getRawOriginal())->only(['label', 'type', 'academic_year_id', 'starts_on', 'ends_on'])->all();

        Livewire::actingAs($actor)->test(EditTerm::class, ['record' => $package->term_id])
            ->fillForm(['type' => Term::TypeSpecialTerm, 'label' => 'Changed active identity'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($identity, collect($package->term->fresh()->getRawOriginal())->only(array_keys($identity))->all());
    }

    public function test_core_activation_does_not_bypass_the_request_deadline_guard(): void
    {
        Mail::fake();
        $actor = $this->staff();
        $faculty = $this->staff(User::StaffRoleFaculty);
        $package = app(ActivateTermCalendarPackage::class)->execute($this->core(), $actor);
        try {
            app(FacultyAvailabilityRequestService::class)->request($package, [$faculty->id], $actor);
            $this->fail('An Active core alone cannot send an availability request.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('calendar_package', $exception->errors());
        }
        $this->assertSame(0, OperationalEvent::query()->where('user_id', $faculty->id)->count());
        Mail::assertNothingOutgoing();
    }

    public function test_informational_exception_can_overlap_no_classes_but_cannot_claim_a_teaching_effect(): void
    {
        $package = $this->core();
        $package->datedExceptions()->create(['starts_on' => '2026-10-09', 'ends_on' => '2026-10-09', 'exception_type' => 'NoClasses', 'label' => 'Approved closure', 'authority_reference' => 'CLOSE58', 'blocks_teaching' => true]);
        $information = $package->datedExceptions()->create(['starts_on' => '2026-10-09', 'ends_on' => '2026-10-09', 'exception_type' => 'InformationOnly', 'label' => 'School notice', 'authority_reference' => 'INFO58', 'blocks_teaching' => false]);
        $this->assertTrue(app(TermCalendarPackageReadinessService::class)->for($package)['ready']);
        $information->update(['blocks_teaching' => true]);
        $this->assertFalse(app(TermCalendarPackageReadinessService::class)->for($package->fresh())['ready']);
    }

    public function test_academic_head_reviews_exact_term_but_cannot_activate(): void
    {
        $head = $this->staff(User::StaffRoleAcademicHead);
        $package = $this->core();
        Livewire::actingAs($head)->test(TermCalendar::class, ['termId' => $package->term_id])
            ->assertSee('Read-only oversight')->assertActionHidden('recordCalendarPackage')->assertActionHidden('activateCalendarPackage');
        $this->expectException(AuthorizationException::class);
        app(ActivateTermCalendarPackage::class)->execute($package, $head);
    }

    private function staff(string $role = User::StaffRoleRegistrar): User
    {
        $roleModel = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        if ($role === User::StaffRoleRegistrar) {
            $roleModel->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'manage-schedules', 'guard_name' => 'web']));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($roleModel);

        return $user;
    }

    public function test_oversight_requires_exact_term_selection_and_preserves_its_source_link(): void
    {
        $head = $this->staff(User::StaffRoleAcademicHead);
        $first = $this->core();
        $first->term->update(['label' => 'Issue58 first source']);
        $first->update(['state' => TermCalendarPackage::StateActive]);
        $second = $this->core();
        $second->term->update(['label' => 'Issue58 second source']);
        $second->update(['state' => TermCalendarPackage::StateActive]);
        TermCalendarWindow::factory()->for($first, 'package')->create(['window_type' => TermCalendarWindow::TypeExaminationPeriod]);

        Livewire::actingAs($head)->test(AcademicApprovals::class)->assertSet('termId', null)
            ->assertSee('No exact Term is selected')->call('selectTerm', $first->term_id)
            ->assertSet('termId', $first->term_id)->assertSee('Issue58 first source')
            ->assertSee(TermPlanningWorkbench::getUrl(['termId' => $first->term_id]))
            ->assertSee('Academic Authority')->assertSee('Grades & Progress')->assertSee('Lifecycle & Completion')
            ->assertDontSee('Academic decisions requiring oversight')->assertDontSee('resolve their stated blockers')
            ->assertViewHas('timetable', null);
    }

    public function test_oversight_findings_link_to_their_exact_calendar_and_program_sources(): void
    {
        $head = $this->staff(User::StaffRoleAcademicHead);
        $package = $this->core();
        $program = Program::factory()->create(['code' => 'OVERSIGHT-SOURCE']);
        $curriculumProgram = Program::factory()->create(['code' => 'OVERSIGHT-CURRICULUM']);
        $curriculum = CurriculumVersion::factory()->for($curriculumProgram)->create();
        $calendarUrl = TermCalendar::getUrl(['termId' => $package->term_id]);
        $programUrl = ProgramResource::getUrl('view', ['record' => $program, 'oversightTermId' => $package->term_id]);

        Livewire::actingAs($head)->test(AcademicApprovals::class, ['termId' => $package->term_id])
            ->assertViewHas('authorityRows', fn ($rows): bool => $rows->firstWhere('program.id', $curriculumProgram->id)['url'] === CurriculumVersionResource::getUrl('review', ['record' => $curriculum, 'oversightTermId' => $package->term_id]))
            ->assertViewHas('attention', fn (array $findings): bool => collect($findings)->contains(fn (array $finding): bool => $finding['title'] === 'Active calendar unavailable' && $finding['owner'] === 'Registrar' && $finding['url'] === $calendarUrl)
                && collect($findings)->contains(fn (array $finding): bool => str_starts_with($finding['title'], 'OVERSIGHT-SOURCE') && $finding['url'] === $programUrl))
            ->assertViewHas('calendar', null)
            ->assertDontSee('Every ready Class Offering must be confirmed');
    }

    public function test_academic_head_drilldowns_return_to_the_selected_oversight_term(): void
    {
        $head = $this->staff(User::StaffRoleAcademicHead);
        $package = $this->core();
        $returnUrl = AcademicApprovals::getUrl(['termId' => $package->term_id]);
        foreach ([
            CatalogCurriculaWorkbench::class,
            ListGradeRosters::class,
            ListStudentLifecycleChanges::class,
            ListStudentProfiles::class,
        ] as $page) {
            Livewire::actingAs($head)->test($page, ['oversightTermId' => $package->term_id])
                ->assertActionExists('returnToOversight', fn (Action $action): bool => $action->getUrl() === $returnUrl);
        }
        Livewire::actingAs($head)->test(TermOverview::class, ['termId' => $package->term_id])
            ->assertSee($returnUrl)->assertSee('Term evidence overview')->assertDontSee('Plan this Term');
    }

    public function test_overview_distinguishes_upcoming_closed_open_and_missing_windows_for_its_exact_term(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 01:00:00', 'UTC'));
        $actor = $this->staff();
        $package = app(ActivateTermCalendarPackage::class)->execute($this->core(), $actor);
        $package->windows()->createMany([
            ['window_type' => TermCalendarWindow::TypeEnrollment, 'opens_on' => '2026-10-10', 'closes_on' => '2026-10-11'],
            ['window_type' => TermCalendarWindow::TypeEnrollmentAdjustment, 'opens_on' => '2026-10-01', 'closes_on' => '2026-10-08'],
            ['window_type' => TermCalendarWindow::TypeCourseDrop, 'opens_on' => '2026-10-09', 'closes_on' => '2026-10-09', 'cutoff_at' => '17:00:00'],
        ]);

        Livewire::actingAs($actor)->test(TermCalendar::class, ['termId' => $package->term_id])
            ->assertViewHas('operationalDates', function ($dates): bool {
                $statuses = $dates->pluck('status', 'type');

                return $statuses['Enrollment'] === 'Upcoming'
                    && $statuses['EnrollmentAdjustment'] === 'Closed'
                    && $statuses['CourseDrop'] === 'Open'
                    && $statuses['GradeEntry'] === 'Dates not configured';
            });
    }

    private function core(): TermCalendarPackage
    {
        $term = Term::factory()->create(['state' => Term::StateDraft]);
        $package = TermCalendarPackage::factory()->for($term)->create(['faculty_availability_due_at' => null]);
        TermTeachingGridRow::factory()->for($package, 'package')->create(['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'breaks' => []]);

        return $package;
    }
}
