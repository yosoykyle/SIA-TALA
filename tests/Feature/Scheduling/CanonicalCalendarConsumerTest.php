<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Calendar\TermTeachingGrid;
use App\Actions\Enrollment\RegistrationShortageProjection;
use App\Actions\Grades\AuthorizeLateGradeEncoding;
use App\Actions\Grades\GradeWindowService;
use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Models\AdmissionApplication;
use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeRoster;
use App\Models\LateGradeAuthorization;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CanonicalCalendarConsumerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_enrollment_gate_and_applicant_availability_share_manila_opening_and_cutoff(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $term = Term::factory()->create();
        $this->window($term, TermCalendarWindow::TypeEnrollment, '2026-08-10', '2026-08-10', '17:00:00');
        $application = AdmissionApplication::factory()->for($term)->create();
        $gates = app(CalendarPhaseGateService::class);
        $projection = app(ReadyApplicantProjectionQuery::class);

        $this->travelTo(Carbon::parse('2026-08-09 15:59:59', 'UTC'));
        $this->assertSame('upcoming', $projection->enrollmentAvailability($application)['status']);
        $this->assertGateClosed(fn () => $gates->assertEnrollmentWindowOpen($term->id));

        $this->travelTo(Carbon::parse('2026-08-09 16:00:00', 'UTC'));
        $this->assertTrue($projection->enrollmentAvailability($application)['available']);
        $gates->assertEnrollmentWindowOpen($term->id);
        $gates->assertEnrollmentEditWindowOpen($term->id);
        $this->assertSame('2026-08-10T17:00:00+08:00', $gates->finalEnrollmentCutoff($term->id)->toIso8601String());
        $this->assertSame('2026-08-10T09:00:00+00:00', $gates->enrollmentDeadline($term->id)->toIso8601String());

        $this->travelTo(Carbon::parse('2026-08-10 09:00:00', 'UTC'));
        $gates->assertEnrollmentWindowOpen($term->id);
        $this->assertTrue($projection->enrollmentAvailability($application)['available']);

        $this->travelTo(Carbon::parse('2026-08-10 09:00:01', 'UTC'));
        $this->assertSame('closed', $projection->enrollmentAvailability($application)['status']);
        $this->assertGateClosed(fn () => $gates->assertEnrollmentWindowOpen($term->id));
        $this->assertGateClosed(fn () => $gates->assertEnrollmentEditWindowOpen($term->id));
    }

    #[DataProvider('independentWindowTypes')]
    public function test_adjustment_and_drop_use_inclusive_manila_dates_and_only_the_exact_active_term(string $type, string $method): void
    {
        $term = Term::factory()->create();
        $otherTerm = Term::factory()->create();
        $this->window($term, $type, '2026-08-10', '2026-08-10');
        $this->window($otherTerm, $type, '2026-08-11', '2026-08-11');
        $gates = app(CalendarPhaseGateService::class);

        $gates->{$method}($term->id, CarbonImmutable::parse('2026-08-10 15:59:59', 'UTC'));
        $gates->{$method}($term->id, CarbonImmutable::parse('2026-08-10 15:59:59.999999', 'UTC'));
        $this->assertGateClosed(fn () => $gates->{$method}($term->id, CarbonImmutable::parse('2026-08-10 16:00:00', 'UTC')));
        $gates->{$method}($otherTerm->id, CarbonImmutable::parse('2026-08-10 16:00:00', 'UTC'));
    }

    /** @return array<string, array{string, string}> */
    public static function independentWindowTypes(): array
    {
        return [
            'adjustment' => [TermCalendarWindow::TypeEnrollmentAdjustment, 'assertEnrollmentAdjustmentWindowOpen'],
            'drop' => [TermCalendarWindow::TypeCourseDrop, 'assertCourseDropWindowOpen'],
        ];
    }

    public function test_legacy_draft_closed_and_other_term_windows_cannot_open_grade_entry(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        $roster = GradeRoster::factory()->create();
        $term = $roster->termOffering->term;
        $service = app(GradeWindowService::class);
        CalendarEvent::factory()->for($term)->create([
            'process_key' => 'grade_entry',
            'state' => CalendarEvent::StateActive,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
        $this->assertFalse($service->isOpen($roster, 'final'));
        $this->assertFalse($service->finalizationClosed($roster));

        $window = $this->window($term, TermCalendarWindow::TypeGradeEntry, '2026-08-10', '2026-08-10', '17:00:00');
        $window->package->update(['state' => TermCalendarPackage::StateDraft]);
        $this->assertFalse($service->isOpen($roster, 'final'));
        $window->package->update(['state' => TermCalendarPackage::StateClosed]);
        $this->assertFalse($service->isOpen($roster, 'final'));
        $this->window(Term::factory()->create(), TermCalendarWindow::TypeGradeEntry, '2026-08-10', '2026-08-10');
        $this->assertFalse($service->isOpen($roster, 'final'));

        $window->package->update(['state' => TermCalendarPackage::StateActive]);
        $this->assertTrue($service->isOpen($roster, 'final', Carbon::parse('2026-08-09 16:00:00', 'UTC')));
        $this->assertTrue($service->isOpen($roster, 'final', Carbon::parse('2026-08-10 09:00:00', 'UTC')));
        $this->assertFalse($service->finalizationClosed($roster, Carbon::parse('2026-08-10 09:00:00', 'UTC')));
        $this->assertFalse($service->isOpen($roster, 'final', Carbon::parse('2026-08-10 09:00:01', 'UTC')));
        $this->assertTrue($service->finalizationClosed($roster, Carbon::parse('2026-08-10 09:00:01', 'UTC')));
    }

    public function test_closed_grade_deadline_marks_drafts_late_and_registrar_authority_reopens_only_its_roster(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 09:00:00', 'UTC'));
        $roster = GradeRoster::factory()->create();
        $otherRoster = GradeRoster::factory()->for($roster->termOffering, 'termOffering')->create();
        $this->window($roster->termOffering->term, TermCalendarWindow::TypeGradeEntry, '2026-08-10', '2026-08-10', '17:00:00');
        $this->artisan('grades:mark-late-rosters')->assertSuccessful();
        $this->assertSame(GradeRoster::StateDraft, $roster->fresh()->state);
        $this->travel(1)->seconds();

        $this->artisan('grades:mark-late-rosters')->assertSuccessful();

        $this->assertSame(GradeRoster::StateLateNotSubmitted, $roster->fresh()->state);
        Role::query()->firstOrCreate(['name' => User::StaffRoleRegistrar, 'guard_name' => 'web']);
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);
        $authority = app(AuthorizeLateGradeEncoding::class)->execute($roster->fresh(), 'final', now(), now()->addHour(), 'Approved class-specific late submission.', $registrar);
        $windows = app(GradeWindowService::class);
        $this->assertTrue($windows->isOpen($roster->fresh(), 'final'));
        $this->assertFalse($windows->isOpen($otherRoster->fresh(), 'final'));
        $this->assertFalse($windows->isOpen($roster->fresh(), 'prelim'));
        $authority->update(['faculty_user_id' => $registrar->id]);
        $this->assertFalse($windows->isOpen($roster->fresh(), 'final'));
        $authority->update(['faculty_user_id' => $roster->faculty_user_id, 'state' => LateGradeAuthorization::StateRevoked]);
        $this->assertFalse($windows->isOpen($roster->fresh(), 'final'));
    }

    public function test_shortage_deadline_uses_active_package_cutoff_and_never_legacy_enrollment_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->for($term)->create();
        $section = Section::factory()->create(['capacity' => 1]);
        CourseEnrollment::factory()->for($enrollment)->create([
            'term_offering_id' => $section->term_offering_id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);
        $proposal = RegistrationProposalVersion::factory()->for($enrollment)->create(['state' => RegistrationProposalVersion::StateConfirmed]);
        RegistrationProposalItem::factory()->create([
            'registration_proposal_version_id' => $proposal->id,
            'term_offering_id' => $section->term_offering_id,
            'section_id' => $section->id,
        ]);
        $enrollment->update(['current_proposal_version_id' => $proposal->id]);
        CalendarEvent::factory()->for($term)->create([
            'process_key' => CalendarEvent::ProcessEnrollment,
            'state' => CalendarEvent::StateActive,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);
        $projection = app(RegistrationShortageProjection::class);

        $this->assertNull($projection->for($enrollment->fresh())[0]['deadline']);
        $this->window($term, TermCalendarWindow::TypeEnrollment, '2026-08-10', '2026-08-10', '17:00:00');

        $this->assertSame('2026-08-10T17:00:00+08:00', $projection->for($enrollment->fresh())[0]['deadline']);
    }

    public function test_legacy_term_grid_and_window_cannot_supply_canonical_scheduling_readiness(): void
    {
        $term = Term::factory()->create(['scheduling_days' => [1], 'scheduling_day_starts_at' => '08:00:00', 'scheduling_day_ends_at' => '17:00:00', 'scheduling_slot_minutes' => 15]);
        CalendarEvent::factory()->for($term)->create(['event_type' => CalendarEvent::TypeWindow, 'process_key' => CalendarEvent::ProcessScheduling, 'state' => CalendarEvent::StateActive]);

        $this->assertSame([], app(TermTeachingGrid::class)->rows($term));
        $this->assertSame(30, app(TermTeachingGrid::class)->slotMinutes($term));
        $this->assertContains('active_core_calendar', app(TermSchedulingReadinessService::class)->evaluateTerm($term)['missing_term_fields']);
    }

    private function window(Term $term, string $type, string $opensOn, string $closesOn, ?string $cutoff = null): TermCalendarWindow
    {
        $package = TermCalendarPackage::factory()->for($term)->create(['state' => TermCalendarPackage::StateActive]);

        return TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => $type,
            'opens_on' => $opensOn,
            'closes_on' => $closesOn,
            'cutoff_at' => $cutoff,
        ]);
    }

    private function assertGateClosed(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a closed exact-Term window.');
        } catch (CalendarGateViolation $exception) {
            $this->assertTrue($exception->context['window_configured']);
        }
    }
}
