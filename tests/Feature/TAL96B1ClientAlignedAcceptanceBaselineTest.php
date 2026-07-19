<?php

namespace Tests\Feature;

use App\Actions\Enrollment\AcademicProgressionService;
use App\Actions\Scheduling\ScheduleGenerationService;
use App\Actions\Scheduling\TermSchedulingReadinessService;
use App\Actions\SystemAdministration\AcceptanceBaselineEnvironmentGuard;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\FacultyTermLoadOverride;
use App\Models\FeeRule;
use App\Models\Program;
use App\Models\Room;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class TAL96B1ClientAlignedAcceptanceBaselineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        $this->clearPersistedAcceptanceBaselineInsideTestTransaction();
    }

    public function test_guarded_command_builds_the_complete_client_aligned_acceptance_baseline(): void
    {
        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('outcome=created', $output);
        $this->assertStringContainsString('students=47', $output);
        $this->assertStringContainsString('scheduling_demands=54', $output);
        $this->assertStringContainsString('readiness=PASS', $output);
        $this->assertStringContainsString('applicant=applicant.demo@example.test', $output);
        $this->assertStringContainsString('system-super-admin=system-admin.demo@example.test', $output);

        $this->assertSame(['DIT', 'DTBM', 'DTHM'], Program::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(47, StudentProfile::query()->count());
        $this->assertSame(10, StudentProfile::query()->where('student_number', 'like', 'DTBM-1A-%')->count());
        $this->assertSame(2, StudentProfile::query()->where('student_number', 'like', 'DTBM-2A-%')->count());
        $this->assertSame(10, StudentProfile::query()->where('student_number', 'like', 'DIT-1A-%')->count());
        $this->assertSame(3, StudentProfile::query()->where('student_number', 'like', 'DIT-2A-%')->count());
        $this->assertSame(15, StudentProfile::query()->where('student_number', 'like', 'DTHM-1A-%')->count());
        $this->assertSame(7, StudentProfile::query()->where('student_number', 'like', 'DTHM-2A-%')->count());
        $this->assertSame([
            StudentProfile::StandingBlockedByPrerequisite => 1,
            StudentProfile::StandingCompletionCandidate => 1,
            StudentProfile::StandingDeficient => 1,
            StudentProfile::StandingGraduationCandidate => 1,
            StudentProfile::StandingIrregular => 2,
            StudentProfile::StandingMustRepeatYear => 1,
            StudentProfile::StandingNotYetEvaluated => 1,
            StudentProfile::StandingProbationary => 1,
            StudentProfile::StandingRegular => 38,
        ], StudentProfile::query()
            ->selectRaw('academic_standing, COUNT(*) as aggregate')
            ->groupBy('academic_standing')
            ->orderBy('academic_standing')
            ->pluck('aggregate', 'academic_standing')
            ->map(fn (int|string $count): int => (int) $count)
            ->all());
        $this->assertEqualsCanonicalizing(
            AcademicProgressionService::standingValues(),
            StudentProfile::query()->distinct()->pluck('academic_standing')->all(),
        );
        $this->assertSame(0, StudentProfile::query()
            ->whereNull('user_id')
            ->orWhereNull('program_id')
            ->orWhereNull('curriculum_version_id')
            ->count());
        $this->assertSame([
            'DIT-1A-001' => StudentProfile::StandingProbationary,
            'DIT-1A-002' => StudentProfile::StandingDeficient,
            'DIT-2A-001' => StudentProfile::StandingBlockedByPrerequisite,
            'DTBM-1A-001' => StudentProfile::StandingRegular,
            'DTBM-1A-002' => StudentProfile::StandingIrregular,
            'DTBM-2A-001' => StudentProfile::StandingIrregular,
            'DTHM-1A-001' => StudentProfile::StandingMustRepeatYear,
            'DTHM-1A-002' => StudentProfile::StandingCompletionCandidate,
            'DTHM-2A-001' => StudentProfile::StandingGraduationCandidate,
            'DTHM-2A-002' => StudentProfile::StandingNotYetEvaluated,
        ], StudentProfile::query()
            ->whereIn('student_number', [
                'DIT-1A-001',
                'DIT-1A-002',
                'DIT-2A-001',
                'DTBM-1A-001',
                'DTBM-1A-002',
                'DTBM-2A-001',
                'DTHM-1A-001',
                'DTHM-1A-002',
                'DTHM-2A-001',
                'DTHM-2A-002',
            ])
            ->orderBy('student_number')
            ->pluck('academic_standing', 'student_number')
            ->all());

        $this->assertSame(40, Course::query()->count());
        $this->assertSame(41, CourseSpecification::query()->count());
        $this->assertSame(41, CourseComponent::query()->count());
        $this->assertSame(3, CurriculumVersion::query()->count());
        $this->assertSame(54, CurriculumEntry::query()->count());
        $this->assertSame(54, TermOffering::query()->count());
        $this->assertSame(54, Section::query()->count());
        $this->assertSame(54, SectionDeliveryGroup::query()->count());
        $this->assertSame(6, Room::query()->count());
        $this->assertSame(40, FacultyQualification::query()->count());
        $this->assertSame(12, FacultyTermLoadOverride::query()->count());
        $this->assertSame(54, SchedulingDemand::query()->count());
        $this->assertSame(54, SchedulingDemand::query()
            ->where('validation_state', SchedulingDemand::ValidationReadyForReview)
            ->count());

        $this->assertSame(0, CourseSpecification::query()
            ->whereJsonContains('allowed_modalities', 'BLENDED')
            ->orWhereJsonContains('allowed_modalities', 'HYFE')
            ->count());
        $this->assertSame(0, TermOffering::query()->whereIn('modality', ['BLENDED', 'HYFE'])->count());
        $this->assertSame(0, SectionDeliveryGroup::query()->whereIn('modality', ['BLENDED', 'HYFE'])->count());
        $this->assertSame(
            'Basic Macroeconomics',
            CourseSpecification::query()
                ->whereBelongsTo(Course::query()->where('code', 'BME09')->sole())
                ->sole()
                ->title,
        );
        $this->assertSame(
            'Art Appreciation',
            CourseSpecification::query()
                ->whereBelongsTo(Course::query()->where('code', 'GE09')->sole())
                ->sole()
                ->title,
        );
        $this->assertSame(
            '2.00',
            CourseSpecification::query()
                ->whereBelongsTo(Course::query()->where('code', 'PE04')->sole())
                ->sole()
                ->credit_units,
        );

        $nstpSpecifications = CourseSpecification::query()
            ->whereBelongsTo(Course::query()->where('code', 'NSTP02')->sole())
            ->orderBy('credit_units')
            ->get();
        $this->assertSame(['2.00', '3.00'], $nstpSpecifications->pluck('credit_units')->all());

        foreach (['DTBM' => '2.00', 'DIT' => '3.00', 'DTHM' => '3.00'] as $programCode => $expectedUnits) {
            $curriculum = CurriculumVersion::query()
                ->whereBelongsTo(Program::query()->where('code', $programCode)->sole())
                ->sole();
            $entry = CurriculumEntry::query()
                ->whereBelongsTo($curriculum)
                ->whereIn('course_specification_id', $nstpSpecifications->modelKeys())
                ->sole();

            $this->assertSame($expectedUnits, $entry->courseSpecification?->credit_units, $programCode);
        }
        $this->assertSame(0, User::query()->where('email', 'not like', '%@example.test')->count());

        $term = Term::query()->where('type', Term::TypeSecondSemester)->sole();
        $this->assertSame([1, 2, 3, 4, 5, 6], $term->scheduling_days);
        $this->assertSame(30, $term->scheduling_slot_minutes);
        $this->assertSame('21.00', $term->default_max_units);
        $readiness = app(TermSchedulingReadinessService::class)->evaluateTerm($term);
        $this->assertTrue($readiness['is_ready'], json_encode($readiness, JSON_PRETTY_PRINT));

        $this->assertSame(3, FeeRule::query()
            ->whereBelongsTo($term)
            ->where('ledger_category', FeeRule::LedgerCategoryDownpayment)
            ->where('amount', 2000)
            ->count());

        foreach ($this->representativeAccounts() as $email => $role) {
            $user = User::query()->where('email', $email)->sole();

            $this->assertTrue(Hash::check('password', $user->password), $email);
            $this->assertTrue($user->hasRole($role), $email);
            $this->assertTrue($user->canAuthenticate(), $email);
            $this->assertTrue($user->hasVerifiedEmail(), $email);
        }

        foreach (['schedule_runs', 'section_meetings', 'enrollments', 'assessments', 'ledger_entries', 'payments', 'payment_attempts', 'webhook_calls'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_complete_baseline_rerun_is_an_exact_no_op(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $before = $this->baselineCounts();
        $latestUpdate = DB::table('scheduling_demands')->max('updated_at');

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('outcome=already_present', $output);
        $this->assertSame($before, $this->baselineCounts());
        $this->assertSame($latestUpdate, DB::table('scheduling_demands')->max('updated_at'));
    }

    public function test_solver_snapshot_maps_course_specific_delivery_groups_to_six_shared_cohorts(): void
    {
        Queue::fake();

        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));

        $term = Term::query()->where('type', Term::TypeSecondSemester)->sole();
        $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();
        $run = app(ScheduleGenerationService::class)->generate($term, $registrar);
        $snapshot = $run->getAttribute('input_snapshot');
        $this->assertIsArray($snapshot);
        $demands = collect($snapshot['scheduling_demands']);
        $cohortMappings = collect($snapshot['student_cohort_groups']);
        $groupNames = SectionDeliveryGroup::query()
            ->whereKey($demands->pluck('section_delivery_group_id')->all())
            ->pluck('name', 'id');

        $this->assertCount(54, $demands);
        $this->assertCount(54, $cohortMappings);
        $this->assertSame(6, $demands->pluck('cohort_or_student_group_id')->unique()->count());
        $this->assertSame(6, $groupNames->unique()->count());

        foreach ($groupNames->unique()->values() as $groupName) {
            $deliveryGroupIds = $groupNames
                ->filter(fn (string $name): bool => $name === $groupName)
                ->keys();
            $sharedCohortIds = $demands
                ->whereIn('section_delivery_group_id', $deliveryGroupIds)
                ->pluck('cohort_or_student_group_id')
                ->unique();

            $this->assertCount(1, $sharedCohortIds, (string) $groupName);
        }
    }

    public function test_complete_baseline_with_extra_master_data_fails_closed_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        Program::query()->create([
            'code' => 'OTHER',
            'name' => 'Unexpected Program',
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_complete_baseline_with_mutated_term_state_fails_closed_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $term = Term::query()->sole();
        $term->update(['state' => Term::StateClosed]);
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertSame(Term::StateClosed, $term->fresh()?->state);
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_complete_baseline_with_unverified_account_fails_closed_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $user = User::query()->where('email', 'student.demo@example.test')->sole();
        $user->forceFill(['email_verified_at' => null])->save();
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_complete_baseline_with_broken_faculty_readiness_fails_closed_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $qualification = FacultyQualification::query()->orderBy('id')->firstOrFail();
        $qualification->update(['is_active' => false]);
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertFalse((bool) $qualification->fresh()?->is_active);
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_rerun_after_operator_edit_preserves_the_change_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $specification = CourseSpecification::query()
            ->whereBelongsTo(Course::query()->where('code', 'BME09')->sole())
            ->sole();
        $specification->update(['title' => 'Changed Acceptance Label']);
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertSame('Changed Acceptance Label', $specification->fresh()?->title);
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_complete_baseline_with_changed_curriculum_content_fails_closed_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $entry = CurriculumEntry::query()->orderBy('id')->firstOrFail();
        $entry->update(['term_label' => 'Changed Semester']);
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertSame('Changed Semester', $entry->fresh()?->term_label);
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_complete_baseline_with_changed_room_content_fails_closed_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $room = Room::query()->where('code', 'LEC-101')->sole();
        $room->update(['capacity' => 39]);
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertSame(39, $room->fresh()?->capacity);
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_complete_baseline_with_changed_student_association_fails_closed_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $student = StudentProfile::query()->where('student_number', 'DTBM-1A-001')->sole();
        $unexpectedProgram = Program::query()->where('code', 'DIT')->sole();
        $student->update(['program_id' => $unexpectedProgram->id]);
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertTrue($student->fresh()?->program()->is($unexpectedProgram));
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_complete_baseline_with_changed_academic_standing_fails_closed_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $student = StudentProfile::query()->where('student_number', 'DTBM-1A-002')->sole();
        $student->update(['academic_standing' => StudentProfile::StandingRegular]);
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertSame(StudentProfile::StandingRegular, $student->fresh()?->academic_standing);
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_complete_baseline_with_changed_staff_role_set_fails_closed_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();
        $registrar->syncRoles([User::StaffRoleRegistrar, User::StaffRoleAcademicHead]);
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertSame(
            [User::StaffRoleAcademicHead, User::StaffRoleRegistrar],
            $registrar->fresh()?->roles()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_partial_or_conflicting_operational_state_fails_without_writes(): void
    {
        Program::query()->create([
            'code' => 'OTHER',
            'name' => 'Existing Unrelated Program',
            'duration_years' => 4,
            'is_active' => true,
        ]);

        $exitCode = Artisan::call('acceptance:seed-client-baseline');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial or conflicting operational data', $output);
        $this->assertSame(1, Program::query()->count());
        $this->assertSame(0, Course::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_read_only_inspection_reports_empty_state_without_writes(): void
    {
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline', ['--check' => true]);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode, $output);
        $this->assertStringContainsString('outcome=inspection_only', $output);
        $this->assertStringContainsString('database=test_tala_db', $output);
        $this->assertStringContainsString('baseline_state=empty', $output);
        $this->assertStringContainsString('readiness=NOT_READY', $output);
        $this->assertStringContainsString('students=0', $output);
        $this->assertStringContainsString('cohorts=0', $output);
        $this->assertStringContainsString('scheduling_demands=0', $output);
        $this->assertStringContainsString('ready_scheduling_demands=0', $output);
        $this->assertStringContainsString('scenario_anchors=0/10', $output);
        $this->assertStringContainsString('downstream_state=EMPTY', $output);
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_read_only_inspection_reports_complete_state_as_ready_without_writes(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:seed-client-baseline'));
        $before = $this->baselineCounts();
        $latestUpdate = DB::table('scheduling_demands')->max('updated_at');

        $exitCode = Artisan::call('acceptance:seed-client-baseline', ['--check' => true]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('outcome=inspection_only', $output);
        $this->assertStringContainsString('database=test_tala_db', $output);
        $this->assertStringContainsString('baseline_state=complete', $output);
        $this->assertStringContainsString('readiness=PASS', $output);
        $this->assertStringContainsString('students=47', $output);
        $this->assertStringContainsString('cohorts=6', $output);
        $this->assertStringContainsString('scheduling_demands=54', $output);
        $this->assertStringContainsString('ready_scheduling_demands=54', $output);
        $this->assertStringContainsString('standing_regular=38', $output);
        $this->assertStringContainsString('standing_irregular=2', $output);
        $this->assertStringContainsString('standing_probationary=1', $output);
        $this->assertStringContainsString('standing_deficient=1', $output);
        $this->assertStringContainsString('standing_blocked_by_prerequisite=1', $output);
        $this->assertStringContainsString('standing_must_repeat_year_level=1', $output);
        $this->assertStringContainsString('standing_completion_candidate=1', $output);
        $this->assertStringContainsString('standing_graduation_candidate=1', $output);
        $this->assertStringContainsString('standing_not_yet_evaluated=1', $output);
        $this->assertStringContainsString('scenario_anchors=10/10', $output);
        $this->assertStringContainsString('downstream_state=EMPTY', $output);
        $this->assertStringContainsString('downstream_schedule_runs=0', $output);
        $this->assertStringContainsString('downstream_section_meetings=0', $output);
        $this->assertStringContainsString('downstream_enrollments=0', $output);
        $this->assertStringContainsString('downstream_assessments=0', $output);
        $this->assertStringContainsString('downstream_ledger_entries=0', $output);
        $this->assertStringContainsString('downstream_payments=0', $output);
        $this->assertStringContainsString('downstream_payment_attempts=0', $output);
        $this->assertStringContainsString('downstream_webhook_calls=0', $output);
        $this->assertSame($before, $this->baselineCounts());
        $this->assertSame($latestUpdate, DB::table('scheduling_demands')->max('updated_at'));
    }

    public function test_read_only_inspection_reports_conflict_without_writes(): void
    {
        Program::query()->create([
            'code' => 'OTHER',
            'name' => 'Existing Unrelated Program',
            'duration_years' => 4,
            'is_active' => true,
        ]);
        $before = $this->baselineCounts();

        $exitCode = Artisan::call('acceptance:seed-client-baseline', ['--check' => true]);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode, $output);
        $this->assertStringContainsString('outcome=inspection_only', $output);
        $this->assertStringContainsString('baseline_state=conflict', $output);
        $this->assertStringContainsString('readiness=NOT_READY', $output);
        $this->assertSame($before, $this->baselineCounts());
    }

    public function test_environment_guard_fails_closed_outside_testing(): void
    {
        $guard = app(AcceptanceBaselineEnvironmentGuard::class);
        $this->app->detectEnvironment(fn (): string => 'local');

        try {
            $guard->assertSafe();
            $this->fail('The acceptance baseline guard accepted a non-testing environment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APP_ENV=testing', $exception->getMessage());
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_database_seeder_remains_free_of_acceptance_operational_data(): void
    {
        app(DatabaseSeeder::class)->run();

        $this->assertSame(0, Program::query()->count());
        $this->assertSame(0, Course::query()->count());
        $this->assertSame(0, StudentProfile::query()->count());
        $this->assertSame(0, SchedulingDemand::query()->count());
    }

    public function test_capacity_authority_rejects_a_universal_student_ceiling(): void
    {
        $prd = file_get_contents(base_path('00_Project_Documents/prd_modules/05_term_offerings_resources.md'));
        $guide = file_get_contents(base_path('00_Project_Documents/TALA-System-Operations-and-Defense-Guide.md'));

        $this->assertIsString($prd);
        $this->assertIsString($guide);
        $this->assertStringNotContainsString('Campus active-student ceiling defaults to 100', $prd);
        $this->assertStringContainsString(
            'TALA does not assume or enforce a universal institution-wide student ceiling.',
            $prd,
        );
        $this->assertStringContainsString(
            'The current population of 47 is evidence of client scale, not a coded maximum.',
            $guide,
        );
    }

    /** @return array<string, string> */
    private function representativeAccounts(): array
    {
        return [
            'applicant.demo@example.test' => 'applicant',
            'student.demo@example.test' => 'student',
            'registrar.demo@example.test' => User::StaffRoleRegistrar,
            'accounting.demo@example.test' => User::StaffRoleAccounting,
            'faculty.demo@example.test' => User::StaffRoleFaculty,
            'academic-head.demo@example.test' => User::StaffRoleAcademicHead,
            'system-admin.demo@example.test' => User::StaffRoleSystemSuperAdmin,
        ];
    }

    /** @return array<string, int> */
    private function baselineCounts(): array
    {
        return [
            'users' => User::query()->count(),
            'students' => StudentProfile::query()->count(),
            'programs' => Program::query()->count(),
            'courses' => Course::query()->count(),
            'curriculum_entries' => CurriculumEntry::query()->count(),
            'offerings' => TermOffering::query()->count(),
            'groups' => SectionDeliveryGroup::query()->count(),
            'demands' => SchedulingDemand::query()->count(),
            'fee_rules' => FeeRule::query()->count(),
        ];
    }

    private function clearPersistedAcceptanceBaselineInsideTestTransaction(): void
    {
        SchedulingDemand::query()->delete();
        SectionDeliveryGroup::query()->delete();
        Section::query()->delete();
        TermOffering::query()->delete();
        FeeRule::query()->delete();
        FacultyQualification::query()->delete();
        FacultyTermLoadOverride::query()->delete();
        CalendarEvent::query()->delete();
        StudentProfile::query()->delete();
        CurriculumEntry::query()->delete();
        CurriculumVersion::query()->delete();
        CourseComponent::query()->delete();
        CourseSpecification::query()->delete();
        Course::query()->delete();
        Room::query()->delete();
        Program::query()->delete();
        DB::table('model_has_roles')->where('model_type', User::class)->delete();
        User::query()->delete();
        Term::query()->delete();
        AcademicYear::query()->delete();
    }
}
