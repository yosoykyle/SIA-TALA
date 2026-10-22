<?php

namespace Tests\Feature;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Enrollment\EnrollmentGateReviewSummary;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Student\Pages\Enrollment as StudentEnrollmentPage;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\LivewireManager;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D3BEnrollmentWindowProposalPlacementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringStartsWith('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([User::StaffRoleRegistrar, User::StaffRoleSystemSuperAdmin, 'student'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_course_enrollments_store_a_non_capacity_holding_section_proposal(): void
    {
        $this->assertTrue(Schema::hasColumn('course_enrollments', 'proposed_section_id'));
        $this->assertTrue(Schema::hasColumn('course_enrollments', 'proposed_at'));
    }

    public function test_section_proposal_is_persisted_without_a_seat_reservation(): void
    {
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($term)->create();
        $offering = TermOffering::factory()->for($term)->create();
        $section = Section::factory()->for($offering, 'termOffering')->create();

        $proposal = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'proposed_section_id' => $section->id,
            'proposed_at' => now(),
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);

        $this->assertTrue($proposal->proposedSection->is($section));
        $this->assertNotNull($proposal->proposed_at);
        $this->assertSame(0, EnrollmentSeatReservation::query()->count());
    }

    public function test_enrollment_window_uses_the_active_exact_term_calendar_package(): void
    {
        $term = Term::factory()->create();
        $evaluatedAt = CarbonImmutable::parse('2026-08-05 10:00:00', config('app.timezone'));
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => $evaluatedAt,
        ]);
        $window = TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => $evaluatedAt->subDay()->toDateString(),
            'closes_on' => $evaluatedAt->addDays(5)->toDateString(),
            'cutoff_at' => '17:00:00',
        ]);

        $service = app(CalendarPhaseGateService::class);
        $service->assertEnrollmentWindowOpen($term->id, $evaluatedAt);

        $this->assertTrue($service->enrollmentWindow($term->id, $evaluatedAt)->is($window));
        $this->assertTrue($service->enrollmentDeadline($term->id, $evaluatedAt)->equalTo(
            CarbonImmutable::parse($window->closes_on->toDateString().' 17:00:00', 'Asia/Manila'),
        ));
    }

    public function test_missing_and_closed_enrollment_windows_have_distinct_blockers(): void
    {
        $term = Term::factory()->create();
        $evaluatedAt = CarbonImmutable::parse('2026-08-05 10:00:00', config('app.timezone'));
        $service = app(CalendarPhaseGateService::class);

        try {
            $service->assertEnrollmentWindowOpen($term->id, $evaluatedAt);
            $this->fail('A missing enrollment window was not blocked.');
        } catch (CalendarGateViolation $exception) {
            $this->assertSame('enrollment_window', $exception->gate);
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }

        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'activated_at' => $evaluatedAt->subWeek(),
        ]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => $evaluatedAt->subDays(5)->toDateString(),
            'closes_on' => $evaluatedAt->subDay()->toDateString(),
            'cutoff_at' => '17:00:00',
        ]);

        try {
            $service->assertEnrollmentWindowOpen($term->id, $evaluatedAt);
            $this->fail('A closed enrollment window was not blocked.');
        } catch (CalendarGateViolation $exception) {
            $this->assertSame('enrollment_window', $exception->gate);
            $this->assertStringContainsString('outside', $exception->getMessage());
        }
    }

    public function test_authorized_staff_start_a_continuing_enrollment_during_the_open_window_idempotently(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $studentProfile = StudentProfile::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->openEnrollmentWindow($term);
        $service = app(StartRegistrationCase::class);

        $first = $service->forContinuingStudent($studentProfile, $term, $registrar, 'RegistrarAssisted', 'SYN-ASSISTED-START');
        $second = $service->forContinuingStudent($studentProfile, $term, $registrar, 'RegistrarAssisted', 'SYN-ASSISTED-START');

        $this->assertTrue($first->is($second));
        $this->assertSame('pending_review', $first->status);
        $this->assertSame(1, Enrollment::query()
            ->whereBelongsTo($studentProfile)
            ->whereBelongsTo($term)
            ->count());
    }

    public function test_continuing_start_reuses_completed_or_terminal_cases_without_reopening_them(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->openEnrollmentWindow($term);
        $service = app(StartRegistrationCase::class);

        foreach (['officially_enrolled', 'cancelled', 'dropped', 'withdrawn'] as $status) {
            $studentProfile = StudentProfile::factory()->create();
            $reason = "Original {$status} reason.";
            $enrollment = Enrollment::factory()
                ->for($studentProfile)
                ->for($term)
                ->create([
                    'status' => $status,
                    'status_reason' => $reason,
                    'canonical_outcome' => $status === 'officially_enrolled' ? Enrollment::OutcomeOfficiallyEnrolled : Enrollment::OutcomeCancelled,
                ]);

            $existing = $service->forContinuingStudent($studentProfile, $term, $registrar, 'RegistrarAssisted', 'SYN-EXISTING-CASE');
            $this->assertTrue($existing->is($enrollment));

            $enrollment->refresh();

            $this->assertSame($status, $enrollment->status);
            $this->assertSame($reason, $enrollment->status_reason);
            $this->assertSame(1, Enrollment::query()
                ->whereBelongsTo($studentProfile)
                ->whereBelongsTo($term)
                ->count());
        }
    }

    public function test_continuing_start_blocks_closed_windows_and_wrong_roles_without_creating_records(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $studentProfile = StudentProfile::factory()->create();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $unauthorized = User::factory()->create(['status' => User::StatusActive]);
        $service = app(StartRegistrationCase::class);

        try {
            $service->forContinuingStudent($studentProfile, $term, $registrar, 'RegistrarAssisted', 'SYN-ASSISTED-START');
            $this->fail('A missing enrollment window did not block continuing enrollment.');
        } catch (CalendarGateViolation $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }

        $this->openEnrollmentWindow($term);

        try {
            $service->forContinuingStudent($studentProfile, $term, $unauthorized);
            $this->fail('An unauthorized actor started continuing enrollment.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Enrollment::query()->whereBelongsTo($studentProfile)->count());
        }
    }

    public function test_student_enrollment_page_is_a_guided_case_status_journey_without_section_shopping(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->for($student)->create([
            'academic_standing' => StudentProfile::StandingIrregular,
        ]);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'student_type' => 'irregular',
                'status' => 'pending_review',
            ]);
        $component = $this->studentEnrollmentComponent($student)
            ->assertOk()
            ->assertSee('Your classes are being prepared')
            ->assertSee('Enrollment payment')
            ->assertSee('No action is needed from you yet.');

        $this->assertFalse(method_exists($component->instance(), 'getTable'));
        $this->assertSame(0, EnrollmentSeatReservation::query()->whereBelongsTo($enrollment)->count());
        $this->assertSame(0, CourseEnrollment::query()->whereBelongsTo($enrollment)->count());
    }

    public function test_retired_staff_confirmation_action_is_absent_for_every_registration_case_state(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $terminalEnrollment = Enrollment::factory()->create([
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $unproposedIrregularEnrollment = Enrollment::factory()->create([
            'student_type' => 'irregular',
            'status' => 'pending_review',
        ]);

        foreach ([$terminalEnrollment, $unproposedIrregularEnrollment] as $enrollment) {
            $actions = collect(Livewire::actingAs($registrar)
                ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
                ->instance()
                ->getCachedHeaderActions())
                ->flatMap(fn ($action): array => $action instanceof ActionGroup ? $action->getFlatActions() : [$action->getName() => $action]);
            $this->assertArrayNotHasKey('confirmPlacement', $actions->all());
        }
    }

    public function test_student_section_shopping_action_is_absent_from_terminal_registration(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->for($student)->create([
            'academic_standing' => StudentProfile::StandingIrregular,
        ]);
        Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'student_type' => 'irregular',
                'status' => 'officially_enrolled',
                'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
                'officially_enrolled_at' => now(),
            ]);
        $this->openEnrollmentWindow($term);
        $component = $this->studentEnrollmentComponent($student);

        $component->assertOk()->assertSee('Registered subjects');
        $this->assertFalse(method_exists($component->instance(), 'getTable'));
    }

    public function test_student_page_uses_the_controlled_selection_basis_instead_of_regular_cohort_policy(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->for($student)->create([
            'academic_standing' => StudentProfile::StandingRegular,
        ]);
        Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'student_type' => 'regular',
                'status' => 'capacity_pending',
            ]);
        $this->actingAs($student);

        $component = app(LivewireManager::class)->test(StudentEnrollmentPage::class);
        $component->assertOk()
            ->assertSee('Your classes are being prepared')
            ->assertDontSee('Proposed cohort DIT-1A');
        $this->assertFalse(method_exists($component->instance(), 'getTable'));
    }

    public function test_student_registration_is_not_exposed_as_a_bulk_selectable_table(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->for($student)->create([
            'academic_standing' => StudentProfile::StandingIrregular,
        ]);
        Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'student_type' => 'irregular',
                'status' => 'pending_review',
            ]);
        $this->openEnrollmentWindow($term);

        $component = $this->studentEnrollmentComponent($student)
            ->assertSee('Your classes are being prepared')
            ->assertSee('Enrollment payment');

        $this->assertFalse(method_exists($component->instance(), 'getTable'));
    }

    public function test_student_page_does_not_expose_the_retired_bulk_replace_proposal_action(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->for($student)->create([
            'academic_standing' => StudentProfile::StandingIrregular,
        ]);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'student_type' => 'irregular',
                'status' => 'pending_review',
            ]);
        $component = $this->studentEnrollmentComponent($student);
        $component->assertOk()->assertSee('Your classes are being prepared');
        $this->assertFalse(method_exists($component->instance(), 'getTable'));
        $this->assertSame(0, CourseEnrollment::query()->whereBelongsTo($enrollment)->count());
    }

    public function test_registrar_can_start_a_continuing_enrollment_from_the_staff_list(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create();
        $this->openEnrollmentWindow($term);

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startContinuingEnrollment', data: [
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'start_method' => 'RegistrarAssisted',
                'authority_reference' => 'SYN-ASSISTED-REGISTRATION-001',
            ])
            ->assertNotified('Enrollment started');

        $this->assertDatabaseHas('enrollments', [
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'status' => 'pending_review',
        ]);
    }

    public function test_registrar_staff_list_records_an_exact_late_start_authority(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create();

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startLateContinuingEnrollment', data: [
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'start_method' => 'LateAuthority',
                'authority_reference' => 'SYN-LATE-START-UI-001',
            ])
            ->assertNotified('Enrollment started');

        $this->assertDatabaseHas('enrollments', [
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'start_method' => 'LateAuthority',
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $this->assertDatabaseHas('registration_case_events', [
            'enrollment_id' => Enrollment::query()
                ->where('student_profile_id', $profile->id)
                ->where('term_id', $term->id)
                ->value('id'),
            'event_type' => 'Started',
            'authority_reference' => 'SYN-LATE-START-UI-001',
        ]);
    }

    public function test_staff_list_truthfully_reports_an_existing_active_enrollment(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create();
        $this->openEnrollmentWindow($term);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'status' => 'pending_review',
                'selection_basis' => Enrollment::SelectionStandardCurriculum,
            ]);

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startContinuingEnrollment', data: [
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'start_method' => 'RegistrarAssisted',
                'authority_reference' => 'SYN-ASSISTED-REGISTRATION-003',
            ])
            ->assertNotified('Existing enrollment opened');

        $this->assertSame(1, Enrollment::query()
            ->whereBelongsTo($profile)
            ->whereBelongsTo($term)
            ->count());
        $this->assertSame('pending_review', $enrollment->fresh()->status);
    }

    public function test_staff_list_rejects_a_cancelled_enrollment_restart_with_a_clear_notice(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create();
        $this->openEnrollmentWindow($term);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'status' => 'cancelled',
                'canonical_outcome' => Enrollment::OutcomeCancelled,
                'status_reason' => 'Student withdrew the request.',
            ]);

        Livewire::actingAs($registrar)
            ->test(ListEnrollments::class)
            ->callAction('startContinuingEnrollment', data: [
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'start_method' => 'RegistrarAssisted',
                'authority_reference' => 'SYN-ASSISTED-REGISTRATION-004',
            ])
            ->assertNotified('Existing enrollment opened');

        $enrollment->refresh();

        $this->assertSame('cancelled', $enrollment->status);
        $this->assertSame('Student withdrew the request.', $enrollment->status_reason);
        $this->assertSame(1, Enrollment::query()
            ->whereBelongsTo($profile)
            ->whereBelongsTo($term)
            ->count());
    }

    public function test_enrollment_summary_explains_the_next_step_and_responsible_office_in_plain_language(): void
    {
        $cancelled = Enrollment::factory()->create([
            'status' => 'cancelled',
            'status_reason' => 'Student withdrew the request.',
        ]);
        $pendingPayment = Enrollment::factory()->create(['status' => 'pending_payment']);
        $summary = app(EnrollmentGateReviewSummary::class);

        $this->assertStringContainsString('cancelled', strtolower($summary->nextStep($cancelled)));
        $this->assertSame('Registrar Office', $summary->responsibleOffice($cancelled));
        $this->assertStringContainsString('payment', strtolower($summary->nextStep($pendingPayment)));
        $this->assertSame('Accounting Office', $summary->responsibleOffice($pendingPayment));
    }

    public function test_staff_enrollment_list_uses_a_direct_record_link_and_assisted_start_recovery(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $case = Enrollment::factory()->create();
        $component = Livewire::actingAs($registrar)->test(ListEnrollments::class)->set('activeTab', 'all_cases');
        $page = $component->instance();

        $this->assertInstanceOf(ListEnrollments::class, $page);

        $table = $page->getTable();
        $recordActions = $table->getRecordActions();
        $startAction = $page->getAction('startContinuingEnrollment', isMounting: false);

        $component->assertCanSeeTableRecords([$case])
            ->assertSee(EnrollmentResource::getUrl('view', ['record' => $case, 'queue' => 'all_cases']), false)
            ->assertSee('Open record');
        $this->assertSame([], $recordActions);
        $this->assertNotNull($startAction);
        $this->assertSame('Continuing student', $startAction->getLabel());
        $this->assertSame('Start continuing enrollment', $startAction->getTooltip());
    }

    public function test_enrollment_record_exposes_canonical_primary_and_grouped_recovery_actions(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $enrollment = Enrollment::factory()->create([
            'student_type' => 'regular',
            'status' => 'pending_review',
        ]);

        $page = Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->instance();
        $this->assertInstanceOf(ViewEnrollment::class, $page);
        $this->assertNotNull($page->getAction('prepareProposal', isMounting: false));
        $this->assertNotNull($page->getAction('reopenCase', isMounting: false));
        $this->assertNotNull($page->getAction('resolveImpactReview', isMounting: false));
        $this->assertSame('preparing', $page->focusedRegistrarView());
    }

    public function test_enrollment_record_never_exposes_retired_placement_or_gate_officialization_actions(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create([
            'academic_standing' => StudentProfile::StandingRegular,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'student_type' => 'regular',
            'status' => 'pre_enrolled',
        ]);
        $this->openEnrollmentWindow($term);

        $actions = collect(Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->getRouteKey()])
            ->instance()
            ->getCachedHeaderActions())
            ->flatMap(fn ($action): array => $action instanceof ActionGroup ? $action->getFlatActions() : [$action->getName() => $action]);

        $this->assertArrayNotHasKey('confirmPlacement', $actions->all());
        $this->assertArrayNotHasKey('recordOfficialEnrollment', $actions->all());
        $this->assertArrayNotHasKey('refreshGateResults', $actions->all());
    }

    public function test_expired_reservation_recovery_command_is_available_to_operations(): void
    {
        $this->artisan('enrollment:release-expired-reservations')
            ->expectsOutput('Expired enrollment reservations released: 0')
            ->assertSuccessful();
    }

    /**
     * @return Testable<StudentEnrollmentPage>
     */
    private function studentEnrollmentComponent(User $student): Testable
    {
        Livewire::actingAs($student);

        return Livewire::test(StudentEnrollmentPage::class);
    }

    private function openEnrollmentWindow(Term $term): TermCalendarWindow
    {
        $package = TermCalendarPackage::factory()->for($term)->create([
            'state' => TermCalendarPackage::StateActive,
            'administrative_starts_on' => now()->subMonth()->toDateString(),
            'administrative_ends_on' => now()->addMonths(6)->toDateString(),
            'classes_start_on' => now()->toDateString(),
            'classes_end_on' => now()->addMonths(5)->toDateString(),
            'activated_at' => now(),
        ]);

        return TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addWeek()->toDateString(),
            'cutoff_at' => '23:59:59',
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
