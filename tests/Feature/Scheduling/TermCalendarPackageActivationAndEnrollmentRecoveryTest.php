<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Calendar\TermCalendarPackageReadinessService;
use App\Filament\Applicant\Pages\Dashboard as ApplicantDashboard;
use App\Filament\Applicant\Pages\Enrollment as ApplicantEnrollment;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Enrollment;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\RegistrationCaseEvent;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TermCalendarPackageActivationAndEnrollmentRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(config('database.test_database'), DB::connection()->getDatabaseName());
        $this->seed(DatabaseSeeder::class);

        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        Role::query()->firstOrCreate(['name' => 'applicant', 'guard_name' => 'web']);
        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    public function test_registrar_can_see_draft_package_readiness_blockers_and_passing_ready_state_on_workbench(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        // 1. Create an invalid draft package with an empty teaching grid.
        $invalidDraft = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'CAL-AUTH-INVALID-001',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->assertSee('Classes run')
            ->assertSee('Not set')
            ->assertSee('Activity dates do not block class planning.')
            ->assertSee('No school teaching days are set.')
            ->assertViewHas('coreReadiness', fn (array $readiness): bool => ! $readiness['ready']);

        // 2. Complete the teaching grid and record the optional activity dates.
        $this->addValidWindowsAndGridToPackage($invalidDraft);

        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->assertSee('Draft')
            ->assertSee('Updated ')
            ->assertSee('Review and activate this draft when ready.')
            ->assertViewHas('overviewPackage', fn (TermCalendarPackage $package): bool => $package->is($invalidDraft))
            ->assertViewHas('coreReadiness', fn (array $readiness): bool => $readiness['ready']);
    }

    public function test_registrar_can_correct_invalid_draft_package_in_place_and_retry_activation(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        $draft = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'INITIAL-REF-001',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
        ]);

        // Attempt activation directly -> fails readiness
        try {
            app(ActivateTermCalendarPackage::class)->execute($draft, $registrar);
            $this->fail('Activation of invalid draft should have thrown ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('package_id', $e->errors());
            $this->assertArrayHasKey('readiness', $e->errors());
        }

        $this->assertSame(TermCalendarPackage::StateDraft, $draft->fresh()->state);
        $this->assertSame(Term::StateDraft, $term->fresh()->state);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        // Correct and activate the draft in place via the unified Save & activate calendar action
        $correctedData = [
            'draft_package_id' => $draft->id,
            'draft_token' => $draft->concurrencyToken(),
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
            'authority_reference' => 'CORRECTED-BOARD-RES-2026-001',
            'authority_date' => '2026-07-15',
            'special_term_schedule_basis' => null,
            'windows' => [
                ['window_type' => TermCalendarWindow::TypeEnrollment, 'opens_on' => '2026-08-01', 'closes_on' => '2026-08-14', 'cutoff_at' => '23:59'],
                ['window_type' => TermCalendarWindow::TypeExaminationPeriod, 'opens_on' => '2026-12-01', 'closes_on' => '2026-12-10', 'cutoff_at' => '23:59'],
                ['window_type' => TermCalendarWindow::TypeGradeEntry, 'opens_on' => '2026-12-11', 'closes_on' => '2026-12-20', 'cutoff_at' => '23:59'],
            ],
            'teaching_grid_rows' => [
                [
                    'day_of_week' => 1,
                    'starts_at' => '08:00',
                    'ends_at' => '17:00',
                    'breaks' => [
                        ['starts_at' => '12:00', 'ends_at' => '13:00'],
                    ],
                ],
            ],
            'dated_exceptions' => [],
        ];

        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->callAction('saveAndActivateCalendar', data: $correctedData)
            ->assertHasNoActionErrors()
            ->assertNotified('Term calendar saved and activated');

        // Confirm package was updated in place without creating a new version and activated atomically
        $freshDraft = $draft->fresh(['windows', 'teachingGridRows']);
        $this->assertSame(1, $freshDraft->version);
        $this->assertSame('INITIAL-REF-001', $freshDraft->authority_reference);
        $this->assertSame(TermCalendarPackage::StateActive, $freshDraft->state);
        $this->assertNotNull($freshDraft->activated_at);
        $this->assertSame(Term::StateActive, $term->fresh()->state);
        $this->assertCount(3, $freshDraft->windows);
        $this->assertCount(1, $freshDraft->teachingGridRows);
        $this->assertEquals([['starts_at' => '12:00', 'ends_at' => '13:00']], $freshDraft->teachingGridRows->first()->breaks);
    }

    public function test_active_and_closed_packages_cannot_be_corrected(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        $activePackage = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'version' => 1,
            'authority_reference' => 'ACTIVE-PACKAGE-001',
        ]);

        $closedPackage = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateClosed,
            'version' => 2,
            'authority_reference' => 'CLOSED-PACKAGE-001',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        // Updating via saveAndActivateCalendar never mutates the existing Active or Closed package in place; it creates a successor version
        foreach ([$activePackage, $closedPackage] as $immutablePackage) {
            Livewire::actingAs($registrar)
                ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
                ->callAction('saveAndActivateCalendar', data: [
                    'draft_package_id' => $immutablePackage->id,
                    'draft_token' => $immutablePackage->concurrencyToken(),
                    'administrative_starts_on' => '2026-08-01',
                    'administrative_ends_on' => '2026-12-31',
                    'classes_start_on' => '2026-08-15',
                    'classes_end_on' => '2026-12-15',
                    'authority_reference' => 'SUCCESSOR-NOT-IN-PLACE',
                    'authority_date' => '2026-07-15',
                    'windows' => [
                        ['window_type' => TermCalendarWindow::TypeEnrollment, 'opens_on' => '2026-08-01', 'closes_on' => '2026-08-14'],
                    ],
                    'teaching_grid_rows' => [
                        ['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '17:00'],
                    ],
                ])
                ->assertHasNoActionErrors();
        }

        $this->assertSame('ACTIVE-PACKAGE-001', $activePackage->fresh()->authority_reference);
        $this->assertSame('CLOSED-PACKAGE-001', $closedPackage->fresh()->authority_reference);
        $this->assertSame(TermCalendarPackage::StateClosed, $closedPackage->fresh()->state);
    }

    public function test_unauthorized_user_and_cross_term_actions_are_rejected(): void
    {
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $term1 = Term::factory()->create();
        $term2 = Term::factory()->create();

        $draftTerm1 = TermCalendarPackage::factory()->for($term1)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'TERM1-DRAFT',
        ]);

        $draftTerm2 = TermCalendarPackage::factory()->for($term2)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'TERM2-DRAFT',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        // AcademicHead has no calendar mutation actions (read-only oversight)
        Livewire::actingAs($academicHead)
            ->test(TermCalendar::class, ['termId' => $term1->id])
            ->assertActionHidden('saveAndActivateCalendar')
            ->assertActionHidden('addOperationalDates');

        // Cross-term: Registrar on Term 1 passing Term 2's draft_package_id never mutates Term 2's package
        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term1->id])
            ->assertActionVisible('saveAndActivateCalendar')
            ->callAction('saveAndActivateCalendar', data: [
                'draft_package_id' => $draftTerm2->id,
                'draft_token' => $draftTerm2->concurrencyToken(),
                'administrative_starts_on' => '2026-08-01',
                'administrative_ends_on' => '2026-12-31',
                'classes_start_on' => '2026-08-15',
                'classes_end_on' => '2026-12-15',
                'authority_reference' => 'CROSS-TERM-ISOLATED',
                'authority_date' => '2026-07-15',
                'windows' => [
                    ['window_type' => TermCalendarWindow::TypeEnrollment, 'opens_on' => '2026-08-01', 'closes_on' => '2026-08-14'],
                ],
                'teaching_grid_rows' => [
                    ['day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '17:00'],
                ],
            ]);

        $this->assertSame('TERM2-DRAFT', $draftTerm2->fresh()->authority_reference);
        $this->assertSame(TermCalendarPackage::StateDraft, $draftTerm2->fresh()->state);

        $draftTerm1->update(['authority_date' => '2026-07-15']);
        $this->addValidWindowsAndGridToPackage($draftTerm1);

        // Cross-term: Registrar on Term 1 still cannot mutate or activate Term 2's package
        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term1->id])
            ->assertActionVisible('saveAndActivateCalendar')
            ->assertActionDoesNotExist('activateCalendarPackage');

        $this->assertSame(TermCalendarPackage::StateDraft, $draftTerm2->fresh()->state);
    }

    public function test_valid_draft_activation_closes_prior_active_package_for_exact_term_only(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $term1 = Term::factory()->create();
        $term2 = Term::factory()->create();

        $term1ActiveV1 = TermCalendarPackage::factory()->for($term1)->create([
            'state' => TermCalendarPackage::StateActive,
            'version' => 1,
            'authority_reference' => 'TERM1-V1',
            'activated_at' => now()->subDays(10),
        ]);

        $term2ActiveV1 = TermCalendarPackage::factory()->for($term2)->create([
            'state' => TermCalendarPackage::StateActive,
            'version' => 1,
            'authority_reference' => 'TERM2-V1',
            'activated_at' => now()->subDays(10),
        ]);

        $term1DraftV2 = TermCalendarPackage::factory()->for($term1)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 2,
            'authority_reference' => 'TERM1-V2',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
            'authority_date' => '2026-07-15',
        ]);
        $this->addValidWindowsAndGridToPackage($term1DraftV2);

        // Activate v2 on Term 1
        app(ActivateTermCalendarPackage::class)->execute($term1DraftV2, $registrar);

        // Term 1 v1 is now Closed
        $this->assertSame(TermCalendarPackage::StateClosed, $term1ActiveV1->fresh()->state);
        $this->assertNotNull($term1ActiveV1->fresh()->closed_at);

        // Term 1 v2 is now Active
        $this->assertSame(TermCalendarPackage::StateActive, $term1DraftV2->fresh()->state);
        $this->assertNotNull($term1DraftV2->fresh()->activated_at);

        // Term 2 v1 remains Active and unclosed (exact-Term isolation)
        $this->assertSame(TermCalendarPackage::StateActive, $term2ActiveV1->fresh()->state);
        $this->assertNull($term2ActiveV1->fresh()->closed_at);
    }

    public function test_enrollment_gate_consumes_only_active_package_approved_enrollment_window(): void
    {
        $term = Term::factory()->create();
        $gate = app(CalendarPhaseGateService::class);
        $now = CarbonImmutable::parse('2026-08-10 10:00:00', config('app.timezone'));

        // 1. No package at all
        try {
            $gate->assertEnrollmentWindowOpen($term->id, $now);
            $this->fail('Expected missing window violation when no package exists.');
        } catch (CalendarGateViolation $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }

        // 2. Only Draft package exists (activation has not happened)
        $draftPackage = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
        ]);
        TermCalendarWindow::factory()->for($draftPackage, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => '2026-08-01',
            'closes_on' => '2026-08-20',
        ]);

        try {
            $gate->assertEnrollmentWindowOpen($term->id, $now);
            $this->fail('Draft package window must never open the enrollment gate.');
        } catch (CalendarGateViolation $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }

        // 3. Active package exists, but window is in the future
        $activePackage = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'version' => 2,
        ]);
        $enrollmentWindow = TermCalendarWindow::factory()->for($activePackage, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => '2026-08-15',
            'closes_on' => '2026-08-25',
            'cutoff_at' => '23:59:59',
        ]);

        try {
            $gate->assertEnrollmentWindowOpen($term->id, $now); // $now is Aug 10, opens Aug 15
            $this->fail('Future window must be blocked as outside window.');
        } catch (CalendarGateViolation $e) {
            $this->assertStringContainsString('outside the configured window', $e->getMessage());
        }

        // 4. Past window (closed)
        $enrollmentWindow->update([
            'opens_on' => '2026-08-01',
            'closes_on' => '2026-08-05',
        ]);

        try {
            $gate->assertEnrollmentWindowOpen($term->id, $now); // $now is Aug 10, closed Aug 5
            $this->fail('Past window must be blocked as outside window.');
        } catch (CalendarGateViolation $e) {
            $this->assertStringContainsString('outside the configured window', $e->getMessage());
        }

        // 5. Open window (now is within bounds)
        $enrollmentWindow->update([
            'opens_on' => '2026-08-01',
            'closes_on' => '2026-08-20',
        ]);

        // Must not throw
        $gate->assertEnrollmentWindowOpen($term->id, $now);
        $this->assertTrue($gate->enrollmentWindow($term->id, $now)->is($enrollmentWindow));
    }

    public function test_applicant_dashboard_displays_accurate_enrollment_availability_and_next_action(): void
    {
        [$application, $term] = $this->admittedReadyApplicant();

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        // Case A: No calendar package exists yet
        Livewire::actingAs($application->user)
            ->test(ApplicantDashboard::class)
            ->assertSee('You are admitted and cleared by the Registrar for enrollment.')
            ->assertSee('Enrollment has not opened yet. The official academic calendar package is being prepared by the Registrar.')
            ->assertSee('Enrollment has not opened yet.')
            ->assertActionDoesNotExist('startRegistration')->assertActionVisible('openEnrollment')
            ->assertDontSee('Click Start enrollment to begin your Registration Case.');

        // Case B: Active package exists with upcoming enrollment window
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
        ]);
        $window = TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->addDays(5)->toDateString(),
            'closes_on' => now()->addDays(15)->toDateString(),
            'cutoff_at' => '17:00:00',
        ]);

        Livewire::actingAs($application->user)
            ->test(ApplicantDashboard::class)
            ->assertSee('Enrollment will open on')
            ->assertActionDoesNotExist('startRegistration')->assertActionVisible('openEnrollment')
            ->assertDontSee('Click Start enrollment to begin your Registration Case.');

        // Case C: Enrollment window is closed
        $window->update([
            'opens_on' => now()->subDays(15)->toDateString(),
            'closes_on' => now()->subDays(2)->toDateString(),
        ]);

        Livewire::actingAs($application->user)
            ->test(ApplicantDashboard::class)
            ->assertSee('Ordinary enrollment for this Term closed on')
            ->assertSee('Contact the Registrar if you require late-enrollment assistance.')
            ->assertActionDoesNotExist('startRegistration')->assertActionVisible('openEnrollment')
            ->assertDontSee('Click Start enrollment to begin your Registration Case.');

        // Case D: Enrollment window is currently open
        $window->update([
            'opens_on' => now()->subDays(2)->toDateString(),
            'closes_on' => now()->addDays(10)->toDateString(),
        ]);

        Livewire::actingAs($application->user)
            ->test(ApplicantDashboard::class)
            ->assertSee('Enrollment is open.')
            ->assertSee('Enrollment is open. Start enrollment to begin your Registration Case.')
            ->assertActionVisible('openEnrollment');

        Livewire::actingAs($application->user)->test(ApplicantEnrollment::class)->assertActionVisible('startRegistration');
    }

    public function test_attempting_start_enrollment_when_window_unavailable_produces_recoverable_explanation_no_500_no_records(): void
    {
        [$application, $term] = $this->admittedReadyApplicant();

        // 1. Calendar window is closed initially
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
        ]);
        $window = TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDays(10)->toDateString(),
            'closes_on' => now()->subDays(1)->toDateString(),
            'cutoff_at' => '23:59:59',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        // When window is unavailable, action is hidden and not invited
        Livewire::actingAs($application->user)
            ->test(ApplicantEnrollment::class)
            ->assertActionHidden('startRegistration')
            ->assertDontSee('Click Start enrollment to begin your Registration Case.');

        // 2. Race condition: Window is open when applicant loads page and clicks action,
        // but window closes before confirmation request is processed by server
        $window->update([
            'opens_on' => now()->subDays(2)->toDateString(),
            'closes_on' => now()->addDays(2)->toDateString(),
        ]);

        $raceComponent = Livewire::actingAs($application->user)
            ->test(ApplicantEnrollment::class)
            ->assertActionVisible('startRegistration');

        // The calendar closes after page load, before the applicant starts.
        $window->update([
            'closes_on' => now()->subDay()->toDateString(),
        ]);

        $raceComponent->call('$refresh')
            ->assertActionHidden('startRegistration')
            ->assertSee('Enrollment is outside the configured window.');

        // Verify zero Registration Cases and zero events created
        $this->assertSame(0, Enrollment::query()->where('credential_user_id', $application->user_id)->count());
        $this->assertSame(0, RegistrationCaseEvent::query()->count());

        // 3. Re-open window -> standard enrollment flow succeeds
        $window->update([
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addWeek()->toDateString(),
        ]);

        Livewire::actingAs($application->user)
            ->test(ApplicantEnrollment::class)
            ->assertActionVisible('startRegistration')
            ->callAction('startRegistration')
            ->assertNotified('Enrollment opened');

        // Exactly one Registration Case created
        $this->assertSame(1, Enrollment::query()->where('credential_user_id', $application->user_id)->count());
        $this->assertSame(1, RegistrationCaseEvent::query()->count());
    }

    public function test_correcting_draft_package_preserves_existing_breaks_when_not_modified(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        $draft = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'INITIAL-REF-BREAKS-001',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
            'authority_date' => '2026-07-15',
        ]);
        $this->addValidWindowsAndGridToPackage($draft);

        // Verify initial breaks are set
        $initialBreaks = $draft->teachingGridRows->first()->breaks;
        $this->assertEquals([['starts_at' => '12:00:00', 'ends_at' => '13:00:00']], $initialBreaks);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        // Mount the workbench and open the saveAndActivateCalendar modal
        $component = Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->assertActionVisible('saveAndActivateCalendar')
            ->mountAction('saveAndActivateCalendar');

        $schemaName = $component->instance()->getMountedActionSchemaName();
        $this->assertNotNull($schemaName);
        $schema = $component->instance()->getSchema($schemaName);
        $this->assertNotNull($schema);
        $state = $schema->getState();

        $this->assertSame($draft->id, (int) $state['draft_package_id']);
        $this->assertNotEmpty($state['teaching_grid_rows']);
        $this->assertEquals([
            ['starts_at' => '12:00', 'ends_at' => '13:00'],
        ], array_values($state['teaching_grid_rows'])[0]['breaks']);

        // Submit without touching breaks; existing authority_reference is retained and breaks survive round-trip.
        $component->setActionData($state);

        $component->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Term calendar saved and activated');

        $freshDraft = $draft->fresh(['teachingGridRows']);
        $this->assertSame('INITIAL-REF-BREAKS-001', $freshDraft->authority_reference);
        $this->assertCount(1, $freshDraft->teachingGridRows);
        $this->assertEquals([
            ['starts_at' => '12:00', 'ends_at' => '13:00'],
        ], $freshDraft->teachingGridRows->first()->breaks);
    }

    public function test_correcting_draft_package_rejects_missing_concurrency_token_and_preserves_draft(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        $draft = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'INITIAL-REF-001',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
            'authority_date' => '2026-07-15',
        ]);
        $this->addValidWindowsAndGridToPackage($draft);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        $component = Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->assertActionVisible('saveAndActivateCalendar')
            ->mountAction('saveAndActivateCalendar');

        $schemaName = $component->instance()->getMountedActionSchemaName();
        $this->assertNotNull($schemaName);
        $schema = $component->instance()->getSchema($schemaName);
        $this->assertNotNull($schema);
        $state = $schema->getState();

        // Omit the concurrency token completely
        $state['draft_token'] = '';
        $state['authority_reference'] = 'MISSING-TOKEN-OVERWRITE-ATTEMPT';
        $component->setActionData($state);

        $component->callMountedAction()
            ->assertHasErrors(['classes_start_on']);

        $this->assertStringContainsString(
            'updated by another user',
            $component->errors()->first('classes_start_on'),
        );

        $freshDraft = $draft->fresh();
        $this->assertSame('INITIAL-REF-001', $freshDraft->authority_reference);
        $this->assertNotSame('MISSING-TOKEN-OVERWRITE-ATTEMPT', $freshDraft->authority_reference);
    }

    public function test_correcting_draft_package_rejects_stale_submission_when_newer_edit_occurs_within_same_second(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        $draft = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'INITIAL-REF-SAME-SEC-001',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
            'authority_date' => '2026-07-15',
        ]);
        $this->addValidWindowsAndGridToPackage($draft);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        // Registrar A mounts workbench and opens form
        $component = Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->assertActionVisible('saveAndActivateCalendar')
            ->mountAction('saveAndActivateCalendar');

        $schemaName = $component->instance()->getMountedActionSchemaName();
        $this->assertNotNull($schemaName);
        $schema = $component->instance()->getSchema($schemaName);
        $this->assertNotNull($schema);
        $state = $schema->getState();

        $this->assertSame($draft->concurrencyToken(), $state['draft_token']);

        // Registrar A prepares changes in their form
        $state['authority_reference'] = 'STALE-SAME-SECOND-OVERWRITE-ATTEMPT';
        $component->setActionData($state);

        // Meanwhile, in the EXACT SAME SECOND (no time travel!), another Registrar saves a newer edit
        $draft->update([
            'authority_reference' => 'NEWER-EDIT-SAVED-IN-SAME-SECOND',
        ]);

        // Verify timestamps are identical in the same second
        $this->assertSame(
            (string) $draft->fresh()->updated_at?->getTimestamp(),
            (string) $draft->updated_at?->getTimestamp(),
        );

        // Registrar A submits now-stale form
        $component->callMountedAction()
            ->assertHasErrors(['classes_start_on']);

        $this->assertStringContainsString(
            'updated by another user',
            $component->errors()->first('classes_start_on'),
        );

        // Confirm the newer edit remains intact and was not overwritten
        $freshDraft = $draft->fresh(['teachingGridRows', 'windows']);
        $this->assertSame('NEWER-EDIT-SAVED-IN-SAME-SECOND', $freshDraft->authority_reference);
        $this->assertNotSame('STALE-SAME-SECOND-OVERWRITE-ATTEMPT', $freshDraft->authority_reference);
    }

    public function test_correcting_draft_package_rejects_stale_form_submission_and_preserves_newer_draft(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        $draft = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'INITIAL-REF-001',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
            'authority_date' => '2026-07-15',
        ]);
        $this->addValidWindowsAndGridToPackage($draft);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        // Mount workbench and open saveAndActivateCalendar modal
        $component = Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->assertActionVisible('saveAndActivateCalendar')
            ->mountAction('saveAndActivateCalendar');

        $schemaName = $component->instance()->getMountedActionSchemaName();
        $this->assertNotNull($schemaName);
        $schema = $component->instance()->getSchema($schemaName);
        $this->assertNotNull($schema);
        $state = $schema->getState();

        $this->assertSame($draft->concurrencyToken(), $state['draft_token']);

        // Registrar A prepares an update in their open form
        $state['authority_reference'] = 'STALE-SUBMISSION-OVERWRITE-ATTEMPT';
        $component->setActionData($state);

        // Advance time and simulate another Registrar updating the draft package in the database
        $this->travel(10)->seconds();
        $draft->update([
            'authority_reference' => 'NEWER-UPDATE-BY-ANOTHER-REGISTRAR',
        ]);

        // Registrar A submits their now-stale form
        $component->callMountedAction()
            ->assertHasErrors(['classes_start_on']);

        $this->assertStringContainsString(
            'updated by another user',
            $component->errors()->first('classes_start_on'),
        );

        // Confirm the newer draft remains intact and was not overwritten
        $freshDraft = $draft->fresh(['teachingGridRows', 'windows']);
        $this->assertSame('NEWER-UPDATE-BY-ANOTHER-REGISTRAR', $freshDraft->authority_reference);
        $this->assertNotSame('STALE-SUBMISSION-OVERWRITE-ATTEMPT', $freshDraft->authority_reference);
    }

    public function test_readiness_service_blocks_activation_on_invalid_breaks_and_conflicting_dated_exceptions(): void
    {
        $term = Term::factory()->create();
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'READINESS-TEST-001',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
            'authority_date' => '2026-07-15',
        ]);
        $this->addValidWindowsAndGridToPackage($package);

        $readinessService = app(TermCalendarPackageReadinessService::class);

        // 1. Initially valid package is ready
        $check = $readinessService->for($package);
        $this->assertTrue($check['ready']);
        $this->assertEmpty($check['blockers']);

        // 2a. Persisted empty break entry cannot silently pass readiness
        $row = $package->teachingGridRows->first();
        $row->update([
            'breaks' => [
                ['starts_at' => null, 'ends_at' => null],
            ],
        ]);
        $check = $readinessService->for($package->fresh(['teachingGridRows']));
        $this->assertFalse($check['ready']);
        $this->assertContains('teaching_grid_break_invalid', collect($check['blockers'])->pluck('code')->all());

        // 2b. Persisted blank break entry
        $row->update([
            'breaks' => [
                [],
            ],
        ]);
        $check = $readinessService->for($package->fresh(['teachingGridRows']));
        $this->assertFalse($check['ready']);
        $this->assertContains('teaching_grid_break_invalid', collect($check['blockers'])->pluck('code')->all());

        // 2c. Invalid break time alignment (e.g. 12:15 is not 30-min aligned)
        $row->update([
            'breaks' => [
                ['starts_at' => '12:15:00', 'ends_at' => '13:00:00'],
            ],
        ]);
        $check = $readinessService->for($package->fresh(['teachingGridRows']));
        $this->assertFalse($check['ready']);
        $this->assertContains('teaching_grid_break_invalid', collect($check['blockers'])->pluck('code')->all());

        // 3. Break out of bounds of daily teaching hours (teaching is 08:00-17:00, break 17:00-18:00)
        $row->update([
            'breaks' => [
                ['starts_at' => '17:00:00', 'ends_at' => '18:00:00'],
            ],
        ]);
        $check = $readinessService->for($package->fresh(['teachingGridRows']));
        $this->assertFalse($check['ready']);
        $this->assertContains('teaching_grid_break_out_of_bounds', collect($check['blockers'])->pluck('code')->all());

        // 4. Overlapping breaks on same day
        $row->update([
            'breaks' => [
                ['starts_at' => '11:00:00', 'ends_at' => '12:30:00'],
                ['starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ],
        ]);
        $check = $readinessService->for($package->fresh(['teachingGridRows']));
        $this->assertFalse($check['ready']);
        $this->assertContains('teaching_grid_break_overlap', collect($check['blockers'])->pluck('code')->all());

        // Restore valid breaks
        $row->update([
            'breaks' => [
                ['starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ],
        ]);

        // 5. Invalid dated exception bounds (outside administrative dates)
        $invalidException = $package->datedExceptions()->create([
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-05',
            'exception_type' => 'Holiday',
            'label' => 'Pre-term holiday',
            'authority_reference' => 'PRES-DEC-001',
            'blocks_teaching' => true,
        ]);
        $check = $readinessService->for($package->fresh(['datedExceptions']));
        $this->assertFalse($check['ready']);
        $this->assertContains('dated_exception_invalid', collect($check['blockers'])->pluck('code')->all());

        // 6. Routine dated exception without authority reference is allowed (no paperwork blocker).
        $invalidException->update([
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-02',
            'authority_reference' => null,
        ]);
        $check = $readinessService->for($package->fresh(['datedExceptions']));
        $this->assertTrue($check['ready']);
        $this->assertNotContains('dated_exception_authority_missing', collect($check['blockers'])->pluck('code')->all());

        // 7a. Compatible overlapping dated exceptions (both block teaching, compatible types e.g. two holidays)
        $invalidException->update([
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-05',
            'exception_type' => 'Holiday',
            'label' => 'Primary Holiday',
            'authority_reference' => 'PRES-DEC-001',
            'blocks_teaching' => true,
        ]);
        $compatibleException = $package->datedExceptions()->create([
            'starts_on' => '2026-09-02',
            'ends_on' => '2026-09-04',
            'exception_type' => 'Suspension',
            'label' => 'Compatible Suspension',
            'authority_reference' => 'PRES-DEC-002',
            'blocks_teaching' => true,
        ]);
        $check = $readinessService->for($package->fresh(['datedExceptions']));
        $this->assertTrue($check['ready']);
        $this->assertNotContains('dated_exception_conflict', collect($check['blockers'])->pluck('code')->all());

        // 7b. Genuinely conflicting overlapping dated exceptions: contradictory teaching effect (blocks vs does not block)
        $compatibleException->update([
            'exception_type' => 'MakeUpAllowed',
            'label' => 'Conflicting Observance',
            'blocks_teaching' => false,
        ]);
        $check = $readinessService->for($package->fresh(['datedExceptions']));
        $this->assertFalse($check['ready']);
        $this->assertContains('dated_exception_conflict', collect($check['blockers'])->pluck('code')->all());

        // 7c. Genuinely conflicting overlapping dated exceptions: contradictory instructional types (Holiday vs Make-Up Day)
        $compatibleException->update([
            'exception_type' => 'MakeUpDay',
            'label' => 'Conflicting Make Up Day',
            'blocks_teaching' => true,
        ]);
        $check = $readinessService->for($package->fresh(['datedExceptions']));
        $this->assertFalse($check['ready']);
        $this->assertContains('dated_exception_conflict', collect($check['blockers'])->pluck('code')->all());

        // Remove the conflict -> now ready again
        $compatibleException->delete();
        $invalidException->delete();
        $check = $readinessService->for($package->fresh(['datedExceptions']));
        $this->assertTrue($check['ready']);
    }

    public function test_activating_invalid_draft_through_workbench_action_shows_blockers_and_preserves_states(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();

        // Draft package with invalid breaks (outside teaching hours)
        $draft = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'INVALID-DRAFT-001',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
            'authority_date' => '2026-07-15',
        ]);
        $this->addValidWindowsAndGridToPackage($draft);

        // Put an out-of-bounds break on the teaching grid row
        $draft->teachingGridRows->first()->update([
            'breaks' => [
                ['starts_at' => '18:00:00', 'ends_at' => '19:00:00'],
            ],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->assertActionVisible('saveAndActivateCalendar')
            ->assertSee('A scheduled break falls outside the daily teaching hours.')
            ->mountAction('saveAndActivateCalendar')
            ->callMountedAction()
            ->assertHasErrors(['readiness']);

        try {
            app(ActivateTermCalendarPackage::class)->execute($draft->fresh(), $registrar);
            $this->fail('The server must reject an unready teaching calendar.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('package_id', $exception->errors());
            $this->assertArrayHasKey('readiness', $exception->errors());
        }

        // Verify draft and term states remain Draft
        $freshDraft = $draft->fresh();
        $this->assertSame(TermCalendarPackage::StateDraft, $freshDraft->state);
        $this->assertNull($freshDraft->activated_at);
        $this->assertSame(Term::StateDraft, $term->fresh()->state);
    }

    public function test_registrar_can_mount_and_submit_activation_modal_with_single_draft_preselected(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create([
            'state' => Term::StateDraft,
            'label' => 'First Semester',
        ]);

        $draft = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'CHED-MEMO-2026-001',
            'authority_date' => '2026-07-01',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
        ]);
        $this->addValidWindowsAndGridToPackage($draft);
        $draft->windows()->create([
            'window_type' => TermCalendarWindow::TypeLateEnrollment,
            'opens_on' => '2026-08-15',
            'closes_on' => '2026-08-20',
            'cutoff_at' => '17:00:00',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        $testable = Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->assertActionVisible('saveAndActivateCalendar')
            ->mountAction('saveAndActivateCalendar')
            ->assertActionMounted('saveAndActivateCalendar')
            ->assertSchemaStateSet(['draft_package_id' => $draft->id])
            ->assertMountedActionModalSee('Save & activate term calendar')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Term calendar saved and activated');

        $this->assertSame(TermCalendarPackage::StateActive, $draft->fresh()->state);
        $this->assertSame($registrar->id, $draft->fresh()->recorded_by);
        $this->assertNotNull($draft->fresh()->activated_at);
        $this->assertSame(Term::StateActive, $term->fresh()->state);

        // Workbench visibly displays Active state
        $testable->assertSee('Calendar ready for class planning')
            ->assertSee('Active')
            ->assertSee('Updated ');

        $this->assertContains(
            TermClasses::getUrl(['termId' => $term->id]),
            array_map(fn ($item): string => $item->getUrl(), $testable->instance()->getWorkspaceNavigation()),
        );
    }

    public function test_multiple_drafts_require_explicit_selection_with_distinguishing_information(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateDraft]);

        $draft1 = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'DRAFT-AUTH-V1',
            'authority_date' => '2026-06-01',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
        ]);
        $this->addValidWindowsAndGridToPackage($draft1);

        $draft2 = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 2,
            'authority_reference' => 'DRAFT-AUTH-V2',
            'authority_date' => '2026-07-01',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-20',
            'classes_end_on' => '2026-12-20',
        ]);
        $this->addValidWindowsAndGridToPackage($draft2);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        // Unified saveAndActivateCalendar preselects the latest draft (v2) while preserving earlier drafts untouched
        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->assertActionVisible('saveAndActivateCalendar')
            ->mountAction('saveAndActivateCalendar')
            ->assertSchemaStateSet(['draft_package_id' => $draft2->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Term calendar saved and activated');

        $this->assertSame(TermCalendarPackage::StateDraft, $draft1->fresh()->state);
        $this->assertSame(TermCalendarPackage::StateActive, $draft2->fresh()->state);
        $this->assertSame(Term::StateActive, $term->fresh()->state);
        $this->assertNotNull($draft2->fresh()->activated_at);
    }

    public function test_older_ready_draft_can_be_selected_and_activated_while_newer_invalid_draft_is_preserved(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateDraft]);
        $olderReady = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'OLDER-READY-AUTH',
            'authority_date' => '2026-07-01',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
        ]);
        $this->addValidWindowsAndGridToPackage($olderReady);
        $newerInvalid = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 2,
            'authority_reference' => 'NEWER-INVALID-AUTH',
        ]);
        $newerBefore = $newerInvalid->fresh()->getAttributes();
        $this->assertTrue(app(TermCalendarPackageReadinessService::class)->for($olderReady)['ready']);
        $this->assertFalse(app(TermCalendarPackageReadinessService::class)->for($newerInvalid)['ready']);

        app(ActivateTermCalendarPackage::class)->execute($olderReady, $registrar, $olderReady->fresh()->concurrencyToken());

        $this->assertSame(TermCalendarPackage::StateActive, $olderReady->fresh()->state);
        $this->assertNotNull($olderReady->fresh()->activated_at);
        $this->assertSame(Term::StateActive, $term->fresh()->state);
        $this->assertSame($newerBefore, $newerInvalid->fresh()->getAttributes());
    }

    public function test_mounted_action_rejects_tampering_to_an_unready_draft_and_displays_its_blockers(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateDraft]);

        $draft = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'UNREADY-AUTH-001',
            'authority_date' => '2026-07-01',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
        ]);
        $this->addValidWindowsAndGridToPackage($draft);

        // Put an out-of-bounds break on the teaching grid row to make it unready
        $draft->teachingGridRows->first()->update([
            'breaks' => [
                ['starts_at' => '18:00:00', 'ends_at' => '19:00:00'],
            ],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        Livewire::actingAs($registrar)
            ->test(TermCalendar::class, ['termId' => $term->id, 'viewTab' => 'overview'])
            ->assertActionVisible('saveAndActivateCalendar')
            ->mountAction('saveAndActivateCalendar')
            ->callMountedAction()
            ->assertHasErrors(['package_id', 'readiness']);

        $this->assertSame(TermCalendarPackage::StateDraft, $draft->fresh()->state);
        $this->assertNull($draft->fresh()->activated_at);
        $this->assertSame(Term::StateDraft, $term->fresh()->state);
    }

    public function test_unexpected_failure_during_activation_halts_cleanly_with_actionable_notification(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateDraft]);

        $draft = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateDraft,
            'version' => 1,
            'authority_reference' => 'FAIL-AUTH-001',
            'authority_date' => '2026-07-01',
            'administrative_starts_on' => '2026-08-01',
            'administrative_ends_on' => '2026-12-31',
            'classes_start_on' => '2026-08-15',
            'classes_end_on' => '2026-12-15',
        ]);
        $this->addValidWindowsAndGridToPackage($draft);

        $dispatcher = TermCalendarPackage::getEventDispatcher();
        TermCalendarPackage::setEventDispatcher(clone $dispatcher);
        TermCalendarPackage::saving(function (): void {
            throw new \RuntimeException('Database connection lost unexpectedly.');
        });

        try {
            app(ActivateTermCalendarPackage::class)->execute($draft, $registrar, $draft->concurrencyToken());
            $this->fail('Unexpected database failure must propagate and roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Database connection lost unexpectedly.', $exception->getMessage());
        } finally {
            TermCalendarPackage::setEventDispatcher($dispatcher);
        }

        // State remains completely Draft with zero partial writes
        $this->assertSame(TermCalendarPackage::StateDraft, $draft->fresh()->state);
        $this->assertSame(Term::StateDraft, $term->fresh()->state);
    }

    private function addValidWindowsAndGridToPackage(TermCalendarPackage $package): void
    {
        $package->windows()->delete();
        $package->teachingGridRows()->delete();

        foreach ([
            TermCalendarWindow::TypeEnrollment => ['2026-08-01', '2026-08-14'],
            TermCalendarWindow::TypeExaminationPeriod => ['2026-12-01', '2026-12-10'],
            TermCalendarWindow::TypeGradeEntry => ['2026-12-11', '2026-12-20'],
        ] as $type => [$open, $close]) {
            $package->windows()->create([
                'window_type' => $type,
                'opens_on' => $open,
                'closes_on' => $close,
                'cutoff_at' => '23:59:59',
            ]);
        }

        $package->teachingGridRows()->create([
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '17:00:00',
            'breaks' => [
                ['starts_at' => '12:00:00', 'ends_at' => '13:00:00'],
            ],
        ]);
    }

    /** @return array{AdmissionApplication, Term} */
    private function admittedReadyApplicant(): array
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $cycle = AdmissionCycle::factory()->for($term)->create();
        $application = AdmissionApplication::factory()->for($cycle, 'admissionCycle')->create([
            'term_id' => $term->id,
            'application_state' => AdmissionApplication::StateAdmitted,
            'application_path' => AdmissionApplication::PathFirstYear,
        ]);
        $application->user->update(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $application->user->assignRole('applicant');

        $requirementSet = AdmissionRequirementSet::factory()->published()->for($cycle)->create([
            'application_path' => $application->application_path,
        ]);
        $submission = ApplicationSubmissionVersion::factory()
            ->for($application, 'application')
            ->for($requirementSet, 'requirementSet')
            ->create(['submitted_by' => $application->user_id]);
        $application->update(['current_submission_version_id' => $submission->id]);

        $decision = AdmissionDecision::factory()->admitted()->for($application, 'application')->create([
            'application_submission_version_id' => $submission->id,
        ]);
        RegistrarEnrollmentClearance::factory()->forAdmittedApplication($application)->create([
            'result' => RegistrarEnrollmentClearance::ResultCleared,
            'external_checks_confirmed' => true,
            'recorded_by' => $decision->decided_by,
        ]);

        return [$application->refresh(), $term];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
