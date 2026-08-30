<?php

namespace Tests\Feature;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Enrollment\EnrollmentGateReviewSummary;
use App\Actions\Enrollment\EnrollmentPlacementService;
use App\Actions\Enrollment\EnrollmentProposalService;
use App\Actions\Enrollment\StartEnrollment;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Student\Pages\Enrollment as StudentEnrollmentPage;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
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
use Illuminate\Validation\ValidationException;
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
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

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
            CarbonImmutable::parse($window->closes_on->toDateString().' 17:00:00', config('app.timezone')),
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
        $service = app(StartEnrollment::class);

        $first = $service->executeContinuing($studentProfile, $term, 'regular', $registrar);
        $second = $service->executeContinuing($studentProfile, $term, 'regular', $registrar);

        $this->assertTrue($first->is($second));
        $this->assertSame('pending_review', $first->status);
        $this->assertSame(1, Enrollment::query()
            ->whereBelongsTo($studentProfile)
            ->whereBelongsTo($term)
            ->count());
    }

    public function test_continuing_start_rejects_completed_or_terminal_enrollments_without_reopening_them(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->openEnrollmentWindow($term);
        $service = app(StartEnrollment::class);

        foreach (['officially_enrolled', 'cancelled', 'dropped', 'withdrawn'] as $status) {
            $studentProfile = StudentProfile::factory()->create();
            $reason = "Original {$status} reason.";
            $enrollment = Enrollment::factory()
                ->for($studentProfile)
                ->for($term)
                ->create([
                    'status' => $status,
                    'status_reason' => $reason,
                ]);

            try {
                $service->executeContinuing($studentProfile, $term, 'regular', $registrar);
                $this->fail("A {$status} enrollment was reported as newly started.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
                $this->assertStringContainsString(
                    str_replace('_', ' ', $status),
                    strtolower($exception->errors()['status'][0]),
                );
            }

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
        $service = app(StartEnrollment::class);

        try {
            $service->executeContinuing($studentProfile, $term, 'regular', $registrar);
            $this->fail('A missing enrollment window did not block continuing enrollment.');
        } catch (CalendarGateViolation $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }

        $this->openEnrollmentWindow($term);

        try {
            $service->executeContinuing($studentProfile, $term, 'regular', $unauthorized);
            $this->fail('An unauthorized actor started continuing enrollment.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Enrollment::query()->whereBelongsTo($studentProfile)->count());
        }
    }

    public function test_irregular_student_proposes_published_sections_without_consuming_capacity(): void
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
        $section = $this->publishedSectionFor($term, $profile);
        $this->openEnrollmentWindow($term);

        app(EnrollmentProposalService::class)->replace(
            enrollment: $enrollment,
            sectionIds: [$section->id],
            actor: $student,
        );

        $courseEnrollment = CourseEnrollment::query()->whereBelongsTo($enrollment)->sole();
        $this->assertSame($section->id, $courseEnrollment->proposed_section_id);
        $this->assertNotNull($courseEnrollment->proposed_at);
        $this->assertSame(0, EnrollmentSeatReservation::query()->count());
        $this->assertSame(0, StudentScheduleBinding::query()->count());
    }

    public function test_irregular_proposal_rejects_wrong_student_and_incompatible_curriculum_without_partial_writes(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $otherStudent = User::factory()->create(['status' => User::StatusActive]);
        $otherStudent->assignRole('student');
        $profile = StudentProfile::factory()->for($student)->create([
            'academic_standing' => StudentProfile::StandingIrregular,
        ]);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['student_type' => 'irregular']);
        $otherProfile = StudentProfile::factory()->create();
        $incompatibleSection = $this->publishedSectionFor($term, $otherProfile);
        $this->openEnrollmentWindow($term);
        $service = app(EnrollmentProposalService::class);

        try {
            $service->replace($enrollment, [$incompatibleSection->id], $otherStudent);
            $this->fail('A different student changed the proposal.');
        } catch (AuthorizationException) {
            $this->assertSame(0, CourseEnrollment::query()->whereBelongsTo($enrollment)->count());
        }

        try {
            $service->replace($enrollment, [$incompatibleSection->id], $student);
            $this->fail('An incompatible curriculum section was proposed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('section_ids', $exception->errors());
            $this->assertSame(0, CourseEnrollment::query()->whereBelongsTo($enrollment)->count());
        }
    }

    public function test_irregular_proposal_blocks_missing_or_closed_windows_without_partial_writes(): void
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
            ->create(['student_type' => 'irregular']);
        $section = $this->publishedSectionFor($term, $profile);
        $service = app(EnrollmentProposalService::class);

        foreach (['missing', 'closed'] as $windowState) {
            if ($windowState === 'closed') {
                $package = TermCalendarPackage::factory()->for($term)->create([
                    'state' => TermCalendarPackage::StateActive,
                    'activated_at' => now()->subWeek(),
                ]);
                TermCalendarWindow::factory()->for($package, 'package')->create([
                    'window_type' => TermCalendarWindow::TypeEnrollment,
                    'opens_on' => now()->subWeek()->toDateString(),
                    'closes_on' => now()->subDay()->toDateString(),
                    'cutoff_at' => '17:00:00',
                ]);
            }

            try {
                $service->replace($enrollment, [$section->id], $student);
                $this->fail("The {$windowState} enrollment window did not block the proposal.");
            } catch (CalendarGateViolation $exception) {
                $this->assertStringContainsString(
                    $windowState === 'missing' ? 'not configured' : 'outside',
                    $exception->getMessage(),
                );
                $this->assertSame(0, CourseEnrollment::query()->whereBelongsTo($enrollment)->count());
            }
        }
    }

    public function test_registrar_confirms_the_complete_irregular_proposal_atomically_with_window_deadlines(): void
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
        $firstSection = $this->publishedSectionFor($term, $profile, dayOfWeek: 1);
        $secondSection = $this->publishedSectionFor($term, $profile, dayOfWeek: 2);
        $this->openEnrollmentWindow($term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $proposals = app(EnrollmentProposalService::class);
        $placement = app(EnrollmentPlacementService::class);

        $proposals->replace($enrollment, [$firstSection->id, $secondSection->id], $student);
        $summary = $placement->confirmComplete($enrollment, $registrar);

        $this->assertSame(2, $summary['courses']);
        $this->assertSame(2, EnrollmentSeatReservation::query()
            ->whereBelongsTo($enrollment)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->count());
        $this->assertSame(2, StudentScheduleBinding::query()
            ->whereHas('courseEnrollment', fn ($query) => $query->whereBelongsTo($enrollment))
            ->where('is_active', true)
            ->count());
        $this->assertSame(
            [app(CalendarPhaseGateService::class)->enrollmentDeadline($term->id)->toDateTimeString()],
            EnrollmentSeatReservation::query()
                ->whereBelongsTo($enrollment)
                ->pluck('deadline')
                ->map(fn ($deadline): string => CarbonImmutable::parse($deadline)->toDateTimeString())
                ->unique()
                ->values()
                ->all(),
        );
        $this->assertTrue(CourseEnrollment::query()
            ->whereBelongsTo($enrollment)
            ->whereNotNull('proposed_section_id')
            ->doesntExist());
        $this->assertSame('pending_payment', $enrollment->fresh()->status);
        $this->assertSame(
            'Finance readiness gate remains unresolved.',
            $enrollment->fresh()->status_reason,
        );
    }

    public function test_registrar_confirms_a_complete_regular_logical_cohort_block(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create([
            'academic_standing' => StudentProfile::StandingRegular,
        ]);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'student_type' => 'regular',
                'status' => 'pending_review',
            ]);
        $firstSection = $this->publishedSectionFor($term, $profile, dayOfWeek: 1, cohortCode: 'DIT-1A');
        $secondSection = $this->publishedSectionFor($term, $profile, dayOfWeek: 2, cohortCode: 'DIT-1A');
        $this->alternativePublishedSection($firstSection, dayOfWeek: 3, cohortCode: 'DIT-1B');
        $this->openEnrollmentWindow($term);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $summary = app(EnrollmentPlacementService::class)
            ->confirmRegularCohort($enrollment, 'DIT-1A', $registrar);

        $this->assertSame(2, $summary['courses']);
        $this->assertEqualsCanonicalizing(
            [$firstSection->id, $secondSection->id],
            EnrollmentSeatReservation::query()
                ->whereBelongsTo($enrollment)
                ->pluck('section_id')
                ->all(),
        );
        $this->assertSame(2, CourseEnrollment::query()->whereBelongsTo($enrollment)->count());
        $this->assertSame(2, StudentScheduleBinding::query()
            ->whereHas('courseEnrollment', fn ($query) => $query->whereBelongsTo($enrollment))
            ->where('is_active', true)
            ->count());
    }

    public function test_regular_cohort_requires_every_eligible_offering_to_be_published_for_the_same_cohort(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create([
            'academic_standing' => StudentProfile::StandingRegular,
        ]);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'student_type' => 'regular',
                'status' => 'pending_review',
            ]);
        $this->publishedSectionFor($term, $profile, dayOfWeek: 1, cohortCode: 'DIT-1A');
        $this->publishedSectionFor($term, $profile, dayOfWeek: 2, cohortCode: 'DIT-1B');
        $this->openEnrollmentWindow($term);
        $placement = app(EnrollmentPlacementService::class);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $this->assertSame([], $placement->regularCohortOptions($enrollment));

        try {
            $placement->confirmRegularCohort($enrollment, 'DIT-1A', $registrar);
            $this->fail('A partial regular cohort was accepted as a complete published block.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cohort_code', $exception->errors());
        }

        $this->assertSame(0, EnrollmentSeatReservation::query()->whereBelongsTo($enrollment)->count());
    }

    public function test_irregular_proposal_rejects_full_sections_before_mutating_the_proposal(): void
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
            ->create(['student_type' => 'irregular']);
        $availableSection = $this->publishedSectionFor($term, $profile, dayOfWeek: 1);
        $fullSection = $this->publishedSectionFor($term, $profile, dayOfWeek: 2);
        $fullSection->update(['capacity' => 1]);
        $occupyingEnrollment = Enrollment::factory()->for($term)->create();
        $occupyingCourse = CourseEnrollment::query()->create([
            'enrollment_id' => $occupyingEnrollment->id,
            'term_offering_id' => $fullSection->term_offering_id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        EnrollmentSeatReservation::query()->create([
            'enrollment_id' => $occupyingEnrollment->id,
            'course_enrollment_id' => $occupyingCourse->id,
            'section_id' => $fullSection->id,
            'status' => EnrollmentSeatReservation::StatusPending,
            'reserved_at' => now(),
            'registrar_user_id' => User::factory()->create()->id,
        ]);
        $this->openEnrollmentWindow($term);

        try {
            app(EnrollmentProposalService::class)->replace(
                $enrollment,
                [$availableSection->id, $fullSection->id],
                $student,
            );
            $this->fail('A full section was saved in the Student proposal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
        }

        $this->assertSame(0, CourseEnrollment::query()->whereBelongsTo($enrollment)->count());
    }

    public function test_complete_irregular_confirmation_rolls_back_every_course_when_one_section_is_full(): void
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
            ->create(['student_type' => 'irregular']);
        $availableSection = $this->publishedSectionFor($term, $profile, dayOfWeek: 1);
        $fullSection = $this->publishedSectionFor($term, $profile, dayOfWeek: 2);
        $fullSection->update(['capacity' => 1]);
        $occupyingEnrollment = Enrollment::factory()->for($term)->create();
        $occupyingCourse = CourseEnrollment::query()->create([
            'enrollment_id' => $occupyingEnrollment->id,
            'term_offering_id' => $fullSection->term_offering_id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        EnrollmentSeatReservation::query()->create([
            'enrollment_id' => $occupyingEnrollment->id,
            'course_enrollment_id' => $occupyingCourse->id,
            'section_id' => $fullSection->id,
            'status' => EnrollmentSeatReservation::StatusPending,
            'reserved_at' => now(),
            'registrar_user_id' => User::factory()->create()->id,
        ]);
        $this->openEnrollmentWindow($term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        foreach ([$availableSection, $fullSection] as $section) {
            CourseEnrollment::query()->create([
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $section->term_offering_id,
                'proposed_section_id' => $section->id,
                'proposed_at' => now(),
                'status' => CourseEnrollment::StatusActive,
                'units_snapshot' => '3.00',
                'added_at' => now(),
            ]);
        }

        try {
            app(EnrollmentPlacementService::class)
                ->confirmComplete($enrollment, $registrar);
            $this->fail('A full section did not roll back the complete placement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('capacity', $exception->errors());
        }

        $this->assertSame(0, EnrollmentSeatReservation::query()->whereBelongsTo($enrollment)->count());
        $this->assertSame(0, StudentScheduleBinding::query()
            ->whereHas('courseEnrollment', fn ($query) => $query->whereBelongsTo($enrollment))
            ->count());
        $this->assertSame(2, CourseEnrollment::query()
            ->whereBelongsTo($enrollment)
            ->whereNotNull('proposed_section_id')
            ->count());
    }

    public function test_complete_irregular_confirmation_locks_the_proposal_rows_before_confirming_them(): void
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
            ->create(['student_type' => 'irregular']);
        $section = $this->publishedSectionFor($term, $profile);
        $this->openEnrollmentWindow($term);
        app(EnrollmentProposalService::class)->replace($enrollment, [$section->id], $student);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });

        app(EnrollmentPlacementService::class)
            ->confirmComplete($enrollment, $this->staff(User::StaffRoleRegistrar));

        $this->assertTrue(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'select `proposed_section_id`')
                && str_contains($query, 'from `course_enrollments`')
                && str_contains($query, 'for update'),
        ), 'The proposal IDs were not selected under a row lock.');
    }

    public function test_registrar_replacement_releases_only_the_superseded_course_placement(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create();
        $firstSection = $this->publishedSectionFor($term, $profile);
        $replacementSection = $this->alternativePublishedSection($firstSection, dayOfWeek: 2);
        $unrelatedSection = $this->publishedSectionFor($term, $profile, dayOfWeek: 3);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $placement = app(EnrollmentPlacementService::class);

        $placement->confirm($enrollment, $firstSection->id, $registrar);
        $placement->confirm($enrollment, $unrelatedSection->id, $registrar);
        $replacement = $placement->confirm($enrollment, $replacementSection->id, $registrar);

        $this->assertSame($replacementSection->id, $replacement['reservation']->section_id);
        $this->assertDatabaseHas('enrollment_seat_reservations', [
            'enrollment_id' => $enrollment->id,
            'section_id' => $firstSection->id,
            'status' => EnrollmentSeatReservation::StatusReleased,
        ]);
        $this->assertDatabaseHas('enrollment_seat_reservations', [
            'enrollment_id' => $enrollment->id,
            'section_id' => $unrelatedSection->id,
            'status' => EnrollmentSeatReservation::StatusPending,
        ]);
        $this->assertDatabaseHas('enrollment_seat_reservations', [
            'enrollment_id' => $enrollment->id,
            'section_id' => $replacementSection->id,
            'status' => EnrollmentSeatReservation::StatusPending,
        ]);
        $this->assertSame(2, EnrollmentSeatReservation::query()
            ->whereBelongsTo($enrollment)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->count());
        $this->assertSame(2, StudentScheduleBinding::query()
            ->whereHas('courseEnrollment', fn ($query) => $query->whereBelongsTo($enrollment))
            ->where('is_active', true)
            ->count());
    }

    public function test_staff_replacement_requires_the_canonical_window_and_records_its_deadline(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['student_type' => 'irregular']);
        $firstSection = $this->publishedSectionFor($term, $profile);
        $replacementSection = $this->alternativePublishedSection($firstSection, dayOfWeek: 2);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $placement = app(EnrollmentPlacementService::class);

        $placement->confirm($enrollment, $firstSection->id, $registrar);

        try {
            $placement->replace($enrollment, $replacementSection->id, $registrar);
            $this->fail('Replacement proceeded without the canonical enrollment window.');
        } catch (CalendarGateViolation $exception) {
            $this->assertSame(
                'Enrollment gate is not configured for this term.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('enrollment_seat_reservations', [
            'enrollment_id' => $enrollment->id,
            'section_id' => $firstSection->id,
            'status' => EnrollmentSeatReservation::StatusPending,
        ]);
        $this->assertDatabaseMissing('enrollment_seat_reservations', [
            'enrollment_id' => $enrollment->id,
            'section_id' => $replacementSection->id,
        ]);

        $this->openEnrollmentWindow($term);
        $summary = $placement->replace($enrollment, $replacementSection->id, $registrar);

        $this->assertSame($replacementSection->id, $summary['reservation']->section_id);
        $this->assertTrue($summary['reservation']->deadline->equalTo(
            app(CalendarPhaseGateService::class)->enrollmentDeadline($term->id),
        ));
        $this->assertDatabaseHas('enrollment_seat_reservations', [
            'enrollment_id' => $enrollment->id,
            'section_id' => $firstSection->id,
            'status' => EnrollmentSeatReservation::StatusReleased,
        ]);
    }

    public function test_cancellation_and_deadline_expiry_release_capacity_and_schedule_bindings(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->for($student)->create([
            'academic_standing' => StudentProfile::StandingIrregular,
        ]);
        $cancelledEnrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['student_type' => 'irregular']);
        $cancelledSection = $this->publishedSectionFor($term, $profile);
        $this->openEnrollmentWindow($term);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $proposals = app(EnrollmentProposalService::class);
        $placement = app(EnrollmentPlacementService::class);

        $proposals->replace($cancelledEnrollment, [$cancelledSection->id], $student);
        $placement->confirmComplete($cancelledEnrollment, $registrar);
        $placement->cancel($cancelledEnrollment, $registrar, 'Student withdrew the enrollment request.');

        $this->assertSame('cancelled', $cancelledEnrollment->fresh()->status);
        $this->assertNotNull($cancelledEnrollment->fresh()->cancelled_at);
        $this->assertSame(EnrollmentSeatReservation::StatusReleased, EnrollmentSeatReservation::query()
            ->whereBelongsTo($cancelledEnrollment)
            ->sole()
            ->status);
        $this->assertFalse(StudentScheduleBinding::query()
            ->whereHas('courseEnrollment', fn ($query) => $query->whereBelongsTo($cancelledEnrollment))
            ->sole()
            ->is_active);

        $expiryEnrollment = Enrollment::factory()
            ->for($profile)
            ->for(Term::factory()->create(['state' => Term::StateActive]))
            ->create(['student_type' => 'irregular']);
        $expiryTerm = $expiryEnrollment->term;
        $expirySection = $this->publishedSectionFor($expiryTerm, $profile);
        $this->openEnrollmentWindow($expiryTerm);
        $proposals->replace($expiryEnrollment, [$expirySection->id], $student);
        $placement->confirmComplete($expiryEnrollment, $registrar);
        EnrollmentSeatReservation::query()
            ->whereBelongsTo($expiryEnrollment)
            ->update(['deadline' => now()->subMinute()]);

        $this->assertSame(1, $placement->releaseExpired(CarbonImmutable::now()));
        $this->assertSame(EnrollmentSeatReservation::StatusReleased, EnrollmentSeatReservation::query()
            ->whereBelongsTo($expiryEnrollment)
            ->sole()
            ->status);
        $this->assertFalse(StudentScheduleBinding::query()
            ->whereHas('courseEnrollment', fn ($query) => $query->whereBelongsTo($expiryEnrollment))
            ->sole()
            ->is_active);
        $this->assertSame('capacity_pending', $expiryEnrollment->fresh()->status);
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
            ->assertSee($enrollment->case_reference)
            ->assertSee('Five checkpoints')
            ->assertSee('The Registrar has not prepared the current proposal yet.');

        $this->assertFalse(method_exists($component->instance(), 'getTable'));
        $this->assertSame(0, EnrollmentSeatReservation::query()->whereBelongsTo($enrollment)->count());
        $this->assertSame(0, CourseEnrollment::query()->whereBelongsTo($enrollment)->count());
    }

    public function test_terminal_enrollments_reject_placement_and_repeated_cancellation_without_side_effects(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $placement = app(EnrollmentPlacementService::class);

        foreach (['officially_enrolled', 'cancelled', 'dropped', 'withdrawn'] as $status) {
            $profile = StudentProfile::factory()->create();
            $enrollment = Enrollment::factory()
                ->for($profile)
                ->for($term)
                ->create([
                    'student_type' => 'regular',
                    'status' => $status,
                    'status_reason' => "Original {$status} reason.",
                ]);
            $section = $this->publishedSectionFor($term, $profile);

            try {
                $placement->confirm($enrollment, $section->id, $registrar);
                $this->fail("Placement mutated a terminal [{$status}] enrollment.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }

            $this->assertSame(0, CourseEnrollment::query()->whereBelongsTo($enrollment)->count());
            $this->assertSame(0, EnrollmentSeatReservation::query()->whereBelongsTo($enrollment)->count());
            $this->assertSame("Original {$status} reason.", $enrollment->fresh()->status_reason);
        }

        $cancelled = Enrollment::factory()
            ->for($term)
            ->create([
                'status' => 'cancelled',
                'cancelled_at' => now()->subDay(),
                'status_reason' => 'Original cancellation reason.',
            ]);
        $originalCancelledAt = $cancelled->cancelled_at;

        try {
            $placement->cancel($cancelled, $registrar, 'Overwritten reason.');
            $this->fail('Repeated cancellation overwrote the terminal enrollment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('Original cancellation reason.', $cancelled->fresh()->status_reason);
        $this->assertTrue($cancelled->fresh()->cancelled_at->equalTo($originalCancelledAt));
    }

    public function test_irregular_replacement_requires_an_existing_same_subject_reservation(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['student_type' => 'irregular']);
        $firstSection = $this->publishedSectionFor($term, $profile);
        $sameSubjectAlternative = $this->alternativePublishedSection($firstSection, dayOfWeek: 2);
        $differentSubject = $this->publishedSectionFor($term, $profile, dayOfWeek: 3);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $placement = app(EnrollmentPlacementService::class);
        $this->openEnrollmentWindow($term);

        try {
            $placement->replace($enrollment, $sameSubjectAlternative->id, $registrar);
            $this->fail('Replacement bypassed the Student proposal without an existing reservation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('section_id', $exception->errors());
        }

        $placement->confirm($enrollment, $firstSection->id, $registrar);

        try {
            $placement->replace($enrollment, $differentSubject->id, $registrar);
            $this->fail('Replacement added a different subject instead of replacing the confirmed subject.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('section_id', $exception->errors());
        }

        $options = $placement->replacementOptions($enrollment);

        $this->assertArrayHasKey($sameSubjectAlternative->id, $options);
        $this->assertArrayNotHasKey($firstSection->id, $options);
        $this->assertArrayNotHasKey($differentSubject->id, $options);
        $this->assertSame(1, EnrollmentSeatReservation::query()
            ->whereBelongsTo($enrollment)
            ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
            ->count());
    }

    public function test_complete_proposal_confirmation_is_restricted_to_irregular_enrollments(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $profile = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['student_type' => 'regular']);
        $section = $this->publishedSectionFor($term, $profile);
        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $section->term_offering_id,
            'proposed_section_id' => $section->id,
            'proposed_at' => now(),
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->openEnrollmentWindow($term);

        try {
            app(EnrollmentPlacementService::class)->confirmComplete($enrollment, $registrar);
            $this->fail('A crafted proposal was confirmed for a non-irregular enrollment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('student_type', $exception->errors());
        }

        $this->assertSame(0, EnrollmentSeatReservation::query()->whereBelongsTo($enrollment)->count());
    }

    public function test_retired_staff_confirmation_action_is_absent_for_every_registration_case_state(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $terminalEnrollment = Enrollment::factory()->create(['status' => 'officially_enrolled']);
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
            ]);
        $this->publishedSectionFor($term, $profile);
        $this->openEnrollmentWindow($term);
        $component = $this->studentEnrollmentComponent($student);

        $component->assertOk()->assertSee('Five checkpoints');
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
            ->assertSee('Standard Curriculum')
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
        $this->publishedSectionFor($term, $profile);
        $this->openEnrollmentWindow($term);

        $component = $this->studentEnrollmentComponent($student)
            ->assertSee('Current proposal')
            ->assertSee('Five checkpoints');

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
        $component->assertOk()->assertSee($enrollment->case_reference);
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
            ->callAction('startContinuingEnrollment', data: [
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
            ->assertNotified('Enrollment already exists');

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
            ->assertNotified('Enrollment already exists');

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

    public function test_staff_enrollment_list_keeps_one_view_action_and_assisted_start_recovery(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $component = Livewire::actingAs($registrar)->test(ListEnrollments::class);
        $page = $component->instance();

        $this->assertInstanceOf(ListEnrollments::class, $page);

        $table = $page->getTable();
        $recordActions = $table->getRecordActions();
        $startAction = collect($page->getCachedHeaderActions())
            ->first(fn ($action): bool => $action->getName() === 'startContinuingEnrollment');

        $this->assertTrue($table->isStackedOnMobile());
        $this->assertCount(1, $recordActions);
        $this->assertSame('view', $recordActions[0]->getName());
        $this->assertNotNull($startAction);
        $this->assertSame('md', $startAction->getLabeledFromBreakpoint());
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
        $actions = collect($page->getCachedHeaderActions());
        $supporting = $actions->first(
            fn ($action): bool => $action instanceof ActionGroup && $action->getLabel() === 'More actions',
        );

        $this->assertNotNull($supporting);
        $this->assertSame(
            [
                'prepareProposal', 'recordGraduatingOverloadAuthority', 'prepareAdjustmentProposal', 'issueProposal', 'recordAssistedConfirmation', 'placeProposal',
                'createAssessment', 'recordIndividualAssessment', 'recordApprovedCoverage',
                'reverseApprovedCoverage', 'downloadPaymentEvidence', 'verifyPaymentEvidence',
                'retrievePayMongoCheckout', 'confirmRecoveredPayMongo', 'rejectRecoveredPayMongo',
                'retryPayMongoEvent', 'linkUnknownPayMongoEvent', 'confirmPayMongoException',
                'rejectPayMongoException',
                'reversePaymentPosting', 'rejectPaymentEvidence', 'exportTermAccount',
                'resolveImpactReview', 'resendOfficialEnrollmentEmail', 'confirmNoAdditionalCost',
                'recordLateAuthority', 'adjustRegistration', 'dropCourse', 'cancelCase',
                'recordLateReopenAuthority', 'reopenCase',
                'printCor', 'printCorHistory',
            ],
            array_keys($supporting->getFlatActions()),
        );
        $this->assertSame(
            ['finalizeOfficialEnrollment'],
            $actions
                ->reject(fn ($action): bool => $action instanceof ActionGroup)
                ->map(fn ($action): string => $action->getName())
                ->values()
                ->all(),
        );
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
        $this->publishedSectionFor($term, $profile, cohortCode: 'DIT-1A');
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

    private function publishedSectionFor(
        Term $term,
        StudentProfile $profile,
        int $dayOfWeek = 1,
        string $cohortCode = 'TEST-1A',
    ): Section {
        $entry = CurriculumEntry::factory()->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'year_level' => 'First Year',
            'term_label' => $term->label,
            'term_type' => $term->type,
        ]);
        $offering = TermOffering::factory()
            ->for($term)
            ->for($entry, 'curriculumEntry')
            ->create([
                'modality' => TermOffering::ModalityOnline,
                'state' => TermOffering::StateScheduled,
            ]);
        $section = Section::factory()->for($offering, 'termOffering')->create([
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => $cohortCode,
            'state' => SectionDeliveryGroup::StateReady,
            'modality' => TermOffering::ModalityOnline,
        ]);
        $component = CourseComponent::factory()->create();
        $demand = SchedulingDemand::factory()
            ->for($offering)
            ->for($component)
            ->for($group)
            ->create(['modality' => TermOffering::ModalityOnline]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', uniqid('tal96d3b', true)),
            'solver_version' => 'tal96d3b-test',
            'published_by' => User::factory()->create()->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => User::factory()->create()->id,
            'room_id' => null,
            'day_of_week' => $dayOfWeek,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => TermOffering::ModalityOnline,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);

        return $section;
    }

    private function alternativePublishedSection(
        Section $source,
        int $dayOfWeek,
        string $cohortCode = 'ALT-1A',
    ): Section {
        $section = Section::factory()->for($source->termOffering, 'termOffering')->create([
            'state' => Section::StateOpen,
        ]);
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'name' => $cohortCode,
            'state' => SectionDeliveryGroup::StateReady,
            'modality' => TermOffering::ModalityOnline,
        ]);
        $demand = SchedulingDemand::factory()
            ->for($source->termOffering)
            ->for(CourseComponent::factory())
            ->for($group)
            ->create(['modality' => TermOffering::ModalityOnline]);
        $run = ScheduleGenerationRun::query()->create([
            'term_id' => $source->termOffering->term_id,
            'status' => ScheduleGenerationRun::StatusPublished,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', uniqid('tal96d3b-alternative', true)),
            'solver_version' => 'tal96d3b-test',
            'published_by' => User::factory()->create()->id,
            'published_at' => now(),
            'publication_version' => 1,
        ]);
        SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => User::factory()->create()->id,
            'room_id' => null,
            'day_of_week' => $dayOfWeek,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'modality' => TermOffering::ModalityOnline,
            'state' => SectionMeeting::StateActive,
            'published_at' => now(),
        ]);

        return $section;
    }
}
