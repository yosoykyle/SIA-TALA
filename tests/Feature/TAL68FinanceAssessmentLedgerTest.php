<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Filament\Resources\Assessments\Pages\ViewAssessment;
use App\Filament\Resources\FeeRules\Pages\CreateFeeRule;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\LedgerEntry;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL68FinanceAssessmentLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private EnrollmentAssessmentService $assessments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleAccounting,
            User::StaffRoleRegistrar,
            User::StaffRoleFaculty,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->assessments = app(EnrollmentAssessmentService::class);
    }

    public function test_accounting_can_configure_fee_rule_using_clean_schema(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $program = Program::factory()->create();
        $term = Term::factory()->create();

        Livewire::actingAs($accounting)
            ->test(CreateFeeRule::class)
            ->fillForm([
                'code' => 'LAB',
                'name' => 'Laboratory Fee',
                'ledger_category' => FeeRule::LedgerCategoryCharge,
                'display_category' => FeeRule::DisplayCategoryLaboratory,
                'program_id' => $program->id,
                'term_id' => $term->id,
                'calculation_type' => FeeRule::CalculationFixed,
                'amount' => '350.00',
                'rate' => null,
                'effective_from' => '2026-06-01',
                'effective_until' => null,
                'is_active' => true,
                'authority' => 'Accounting fee matrix approval',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('fee_rules', [
            'code' => 'LAB',
            'name' => 'Laboratory Fee',
            'ledger_category' => FeeRule::LedgerCategoryCharge,
            'display_category' => FeeRule::DisplayCategoryLaboratory,
            'program_id' => $program->id,
            'term_id' => $term->id,
            'calculation_type' => FeeRule::CalculationFixed,
            'amount' => '350.00',
            'authority' => 'Accounting fee matrix approval',
        ]);
    }

    public function test_accounting_can_store_and_assess_a_per_unit_peso_rate_above_one_thousand(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00]);

        Livewire::actingAs($accounting)
            ->test(CreateFeeRule::class)
            ->fillForm([
                'code' => 'TUITION',
                'name' => 'Tuition Fee',
                'ledger_category' => FeeRule::LedgerCategoryCharge,
                'display_category' => FeeRule::DisplayCategoryTuition,
                'program_id' => $enrollment->studentProfile->program_id,
                'term_id' => $enrollment->term_id,
                'calculation_type' => FeeRule::CalculationPerUnit,
                'amount' => null,
                'rate' => '1600.50',
                'effective_from' => '2026-06-01',
                'effective_until' => null,
                'is_active' => true,
                'authority' => 'Accounting fee matrix approval',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->feeRule(
            'DOWNPAYMENT',
            FeeRule::DisplayCategoryDownpayment,
            FeeRule::CalculationFixed,
            amount: '1500.00',
            programId: $enrollment->studentProfile->program_id,
            termId: $enrollment->term_id,
        );

        $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));
        $tuitionLine = AssessmentLine::query()
            ->where('assessment_id', $assessment->id)
            ->where('line_type', FeeRule::DisplayCategoryTuition)
            ->sole();

        $this->assertSame('1600.50', (string) FeeRule::query()->where('code', 'TUITION')->sole()->rate);
        $this->assertSame('1600.50', (string) $tuitionLine->rate);
        $this->assertSame('4801.50', (string) $tuitionLine->amount);
    }

    public function test_accounting_form_requires_program_and_term_for_a_downpayment_rule(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);

        Livewire::actingAs($accounting)
            ->test(CreateFeeRule::class)
            ->fillForm([
                'code' => 'DOWNPAYMENT',
                'name' => 'Required Downpayment',
                'ledger_category' => FeeRule::LedgerCategoryDownpayment,
                'display_category' => FeeRule::DisplayCategoryDownpayment,
                'program_id' => null,
                'term_id' => null,
                'calculation_type' => FeeRule::CalculationFixed,
                'amount' => '1500.00',
                'rate' => null,
                'effective_from' => '2026-06-01',
                'effective_until' => null,
                'is_active' => true,
                'authority' => 'Accounting fee matrix approval',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'program_id' => 'required',
                'term_id' => 'required',
            ]);

        $this->assertSame(0, FeeRule::query()->where('code', 'DOWNPAYMENT')->count());
    }

    public function test_non_accounting_user_cannot_create_or_activate_finance_records(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00]);
        $assessment = $this->assessments->generateDraft($enrollment, $this->staff(User::StaffRoleAccounting), CarbonImmutable::parse('2026-06-10'));

        $this->assertFalse(Gate::forUser($faculty)->allows('create', FeeRule::class));
        $this->assertFalse(Gate::forUser($faculty)->allows('activate', $assessment));

        try {
            $this->assessments->generateDraft($enrollment, $faculty, CarbonImmutable::parse('2026-06-10'));
            $this->fail('Unauthorized assessment generation was not rejected.');
        } catch (AuthorizationException) {
            $this->assertSame(1, Assessment::query()->count());
        }

        try {
            $this->assessments->activate($assessment, $faculty, CarbonImmutable::parse('2026-06-11'));
            $this->fail('Unauthorized assessment activation was not rejected.');
        } catch (AuthorizationException) {
            $this->assertSame(0, LedgerEntry::query()->count());
        }
    }

    public function test_assessment_generation_from_pending_payment_enrollment_is_deterministic_and_uses_enrolled_units(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00, 2.00]);
        $this->configureMvpFeeRules($enrollment);

        $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));
        $duplicate = $this->assessments->generateDraft($enrollment->fresh(), $accounting, CarbonImmutable::parse('2026-06-10'));

        $this->assertSame($assessment->id, $duplicate->id);
        $this->assertSame(1, Assessment::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertSame(Assessment::StateDraft, $assessment->state);
        $this->assertSame('5800.00', (string) $assessment->fresh()->total);
        $this->assertSame('1500.00', (string) $assessment->fresh()->required_downpayment);
        $this->assertSame(6, AssessmentLine::query()->where('assessment_id', $assessment->id)->count());
        $this->assertSame(1, PaymentScheduleRow::query()->where('assessment_id', $assessment->id)->count());

        $tuitionLines = AssessmentLine::query()
            ->where('assessment_id', $assessment->id)
            ->where('line_type', FeeRule::DisplayCategoryTuition)
            ->orderBy('quantity')
            ->get();

        $this->assertSame(['2.0000', '3.0000'], $tuitionLines->pluck('quantity')->map(fn ($value): string => (string) $value)->all());
        $this->assertSame(['1800.00', '2700.00'], $tuitionLines->pluck('amount')->map(fn ($value): string => (string) $value)->all());
        $this->assertTrue($tuitionLines->every(fn (AssessmentLine $line): bool => $line->course_enrollment_id !== null));

        $this->assertSame('650.00', (string) AssessmentLine::query()
            ->where('assessment_id', $assessment->id)
            ->where('line_type', FeeRule::DisplayCategoryMiscellaneous)
            ->sole()
            ->amount);
        $this->assertSame('300.00', (string) AssessmentLine::query()->where('line_type', FeeRule::DisplayCategoryLaboratory)->sole()->amount);
        $this->assertSame('250.00', (string) AssessmentLine::query()->where('line_type', FeeRule::DisplayCategoryOther)->sole()->amount);
        $this->assertSame('100.00', (string) AssessmentLine::query()->where('line_type', FeeRule::DisplayCategoryRegistration)->sole()->amount);
    }

    public function test_exact_program_and_term_rule_beats_a_newer_global_rule(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00]);
        $programId = $enrollment->studentProfile->program_id;
        $termId = $enrollment->term_id;

        $this->feeRule('MISC', FeeRule::DisplayCategoryMiscellaneous, FeeRule::CalculationFixed, amount: '650.00', programId: $programId, termId: $termId, effectiveFrom: '2026-05-01');
        $this->feeRule('MISC', FeeRule::DisplayCategoryMiscellaneous, FeeRule::CalculationFixed, amount: '999.00', effectiveFrom: '2026-06-09');
        $this->feeRule('DOWNPAYMENT', FeeRule::DisplayCategoryDownpayment, FeeRule::CalculationFixed, amount: '1500.00', programId: $programId, termId: $termId);

        $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));

        $this->assertSame('650.00', (string) AssessmentLine::query()
            ->where('assessment_id', $assessment->id)
            ->where('line_type', FeeRule::DisplayCategoryMiscellaneous)
            ->sole()
            ->amount);
    }

    public function test_term_scoped_rule_beats_a_newer_program_scoped_rule(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00]);
        $programId = $enrollment->studentProfile->program_id;
        $termId = $enrollment->term_id;

        $this->feeRule('OTHER', FeeRule::DisplayCategoryOther, FeeRule::CalculationFixed, amount: '300.00', termId: $termId, effectiveFrom: '2026-05-01');
        $this->feeRule('OTHER', FeeRule::DisplayCategoryOther, FeeRule::CalculationFixed, amount: '900.00', programId: $programId, effectiveFrom: '2026-06-09');
        $this->feeRule('DOWNPAYMENT', FeeRule::DisplayCategoryDownpayment, FeeRule::CalculationFixed, amount: '1500.00', programId: $programId, termId: $termId);

        $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));

        $this->assertSame('300.00', (string) AssessmentLine::query()
            ->where('assessment_id', $assessment->id)
            ->where('line_type', FeeRule::DisplayCategoryOther)
            ->sole()
            ->amount);
    }

    public function test_newest_effective_date_wins_within_the_same_scope_rank(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00]);
        $programId = $enrollment->studentProfile->program_id;
        $termId = $enrollment->term_id;

        $this->feeRule('LAB', FeeRule::DisplayCategoryLaboratory, FeeRule::CalculationFixed, amount: '300.00', programId: $programId, termId: $termId, effectiveFrom: '2026-05-01');
        $this->feeRule('LAB', FeeRule::DisplayCategoryLaboratory, FeeRule::CalculationFixed, amount: '450.00', programId: $programId, termId: $termId, effectiveFrom: '2026-06-01');
        $this->feeRule('DOWNPAYMENT', FeeRule::DisplayCategoryDownpayment, FeeRule::CalculationFixed, amount: '1500.00', programId: $programId, termId: $termId);

        $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));

        $this->assertSame('450.00', (string) AssessmentLine::query()
            ->where('assessment_id', $assessment->id)
            ->where('line_type', FeeRule::DisplayCategoryLaboratory)
            ->sole()
            ->amount);
    }

    public function test_newest_id_breaks_a_priority_tie_deterministically(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00]);
        $programId = $enrollment->studentProfile->program_id;
        $termId = $enrollment->term_id;

        $this->feeRule('REG', FeeRule::DisplayCategoryRegistration, FeeRule::CalculationFixed, amount: '100.00');
        $winningRule = $this->feeRule('REG', FeeRule::DisplayCategoryRegistration, FeeRule::CalculationFixed, amount: '125.00');
        $this->feeRule('DOWNPAYMENT', FeeRule::DisplayCategoryDownpayment, FeeRule::CalculationFixed, amount: '1500.00', programId: $programId, termId: $termId);

        $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));
        $line = AssessmentLine::query()
            ->where('assessment_id', $assessment->id)
            ->where('line_type', FeeRule::DisplayCategoryRegistration)
            ->sole();

        $this->assertSame($winningRule->id, $line->fee_rule_id);
        $this->assertSame('125.00', (string) $line->amount);
    }

    public function test_missing_required_downpayment_configuration_blocks_activation_without_partial_ledger_writes(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00]);
        $this->configureMvpFeeRules($enrollment, includeDownpayment: false);
        $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));

        try {
            $this->assessments->activate($assessment, $accounting, CarbonImmutable::parse('2026-06-11'));
            $this->fail('Activation without a configured downpayment was not rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('downpayment', $exception->errors());
            $this->assertSame(Assessment::StateDraft, $assessment->fresh()->state);
            $this->assertSame(0, LedgerEntry::query()->count());
        }
    }

    public function test_broader_downpayment_scopes_do_not_satisfy_activation_or_write_ledger_entries(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);

        foreach ([
            'global' => [false, false],
            'program-only' => [true, false],
            'term-only' => [false, true],
        ] as [$scopeProgram, $scopeTerm]) {
            $enrollment = $this->placedEnrollmentWithCourseUnits([3.00]);
            $programId = $enrollment->studentProfile->program_id;
            $termId = $enrollment->term_id;

            $this->feeRule('TUITION-'.$enrollment->id, FeeRule::DisplayCategoryTuition, FeeRule::CalculationPerUnit, rate: '900.00');
            $this->feeRule(
                'DOWNPAYMENT-'.$enrollment->id,
                FeeRule::DisplayCategoryDownpayment,
                FeeRule::CalculationFixed,
                amount: '1500.00',
                programId: $scopeProgram ? $programId : null,
                termId: $scopeTerm ? $termId : null,
            );

            $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));

            $this->assertSame('0.00', (string) $assessment->required_downpayment);
            $this->assertSame(0, PaymentScheduleRow::query()->where('assessment_id', $assessment->id)->count());

            try {
                $this->assessments->activate($assessment, $accounting, CarbonImmutable::parse('2026-06-11'));
                $this->fail('Activation with a broader-scoped downpayment was not rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('downpayment', $exception->errors());
                $this->assertSame(Assessment::StateDraft, $assessment->fresh()->state);
                $this->assertSame(0, LedgerEntry::query()->where('enrollment_id', $enrollment->id)->count());
            }
        }
    }

    public function test_exact_program_and_term_downpayment_permits_activation_and_posts_charge_ledger_entries_idempotently(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00, 2.00]);
        $this->configureMvpFeeRules($enrollment);
        $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));

        $activated = $this->assessments->activate($assessment, $accounting, CarbonImmutable::parse('2026-06-11 09:00:00'));
        $this->assessments->activate($activated->fresh(), $accounting, CarbonImmutable::parse('2026-06-11 09:05:00'));

        $this->assertSame(Assessment::StateActive, $activated->fresh()->state);
        $this->assertSame(6, LedgerEntry::query()->count());
        $this->assertSame('5800.00', $this->assessments->ledgerBalanceFor($enrollment->studentProfile, $enrollment->term));
        $this->assertFalse(Schema::hasColumn('ledger_entries', 'running_balance'));

        $ledgerEntries = LedgerEntry::query()->orderBy('id')->get();
        $this->assertTrue($ledgerEntries->every(fn (LedgerEntry $entry): bool => $entry->direction === LedgerEntry::DirectionCharge));
        $this->assertTrue($ledgerEntries->every(fn (LedgerEntry $entry): bool => $entry->source_type === AssessmentLine::class));
        $this->assertSame('5800.00', number_format((float) $ledgerEntries->sum('amount'), 2, '.', ''));

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('payment_attempts')->count());
        $this->assertSame(0, DB::table('payment_allocations')->count());
        $this->assertSame(0, DB::table('output_access_logs')->count());

        if (Schema::hasTable('cor_verifications')) {
            $this->assertSame(0, DB::table('cor_verifications')->count());
        }
    }

    public function test_registrar_can_view_finance_summary_but_cannot_mutate_assessment_or_ledger(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $enrollment = $this->placedEnrollmentWithCourseUnits([3.00]);
        $this->configureMvpFeeRules($enrollment);
        $assessment = $this->assessments->generateDraft($enrollment, $accounting, CarbonImmutable::parse('2026-06-10'));
        $this->assessments->activate($assessment, $accounting, CarbonImmutable::parse('2026-06-11'));

        $this->assertTrue(Gate::forUser($registrar)->allows('view', $assessment));
        $this->assertTrue(Gate::forUser($registrar)->allows('view', LedgerEntry::query()->firstOrFail()));
        $this->assertFalse(Gate::forUser($registrar)->allows('create', FeeRule::class));
        $this->assertFalse(Gate::forUser($registrar)->allows('activate', $assessment->fresh()));

        Livewire::actingAs($registrar)
            ->test(ViewAssessment::class, ['record' => $assessment->getRouteKey()])
            ->assertActionHidden('activateAssessment');
    }

    /**
     * @param  list<float>  $units
     */
    private function placedEnrollmentWithCourseUnits(array $units): Enrollment
    {
        $term = Term::factory()->create([
            'starts_on' => '2026-08-01',
        ]);
        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateActive,
        ]);
        $studentProfile = StudentProfile::factory()->for($program)->create([
            'curriculum_version_id' => $curriculum->id,
        ]);
        $enrollment = Enrollment::factory()
            ->for($studentProfile)
            ->for($term)
            ->create(['status' => 'pending_payment']);

        foreach ($units as $unit) {
            $specification = CourseSpecification::factory()->create([
                'credit_units' => $unit,
                'state' => CourseSpecification::StateActive,
            ]);
            $entry = CurriculumEntry::factory()
                ->for($curriculum)
                ->for($specification, 'courseSpecification')
                ->create();
            $offering = TermOffering::factory()
                ->for($term)
                ->for($entry, 'curriculumEntry')
                ->create([
                    'state' => TermOffering::StateScheduled,
                ]);

            CourseEnrollment::query()->create([
                'enrollment_id' => $enrollment->id,
                'term_offering_id' => $offering->id,
                'status' => CourseEnrollment::StatusActive,
                'units_snapshot' => number_format($unit, 2, '.', ''),
                'added_at' => now(),
            ]);
        }

        return $enrollment->refresh();
    }

    private function configureMvpFeeRules(Enrollment $enrollment, bool $includeDownpayment = true): void
    {
        $programId = $enrollment->studentProfile->program_id;
        $termId = $enrollment->term_id;

        $this->feeRule('TUITION', FeeRule::DisplayCategoryTuition, FeeRule::CalculationPerUnit, rate: '900.00');
        $this->feeRule('LAB', FeeRule::DisplayCategoryLaboratory, FeeRule::CalculationFixed, amount: '300.00', programId: $programId, termId: $termId);
        $this->feeRule('MISC', FeeRule::DisplayCategoryMiscellaneous, FeeRule::CalculationFixed, amount: '400.00');
        $this->feeRule('MISC', FeeRule::DisplayCategoryMiscellaneous, FeeRule::CalculationFixed, amount: '650.00', programId: $programId, termId: $termId);
        $this->feeRule('OTHER', FeeRule::DisplayCategoryOther, FeeRule::CalculationFixed, amount: '250.00', termId: $termId);
        $this->feeRule('REG', FeeRule::DisplayCategoryRegistration, FeeRule::CalculationFixed, amount: '100.00', programId: $programId);
        $this->feeRule('MANUAL', FeeRule::DisplayCategoryOther, FeeRule::CalculationManual, amount: '999.00', programId: $programId, termId: $termId);

        if ($includeDownpayment) {
            $this->feeRule('DOWNPAYMENT', FeeRule::DisplayCategoryDownpayment, FeeRule::CalculationFixed, amount: '1500.00', programId: $programId, termId: $termId);
        }
    }

    private function feeRule(
        string $code,
        string $displayCategory,
        string $calculationType,
        ?string $amount = null,
        ?string $rate = null,
        ?int $programId = null,
        ?int $termId = null,
        string $effectiveFrom = '2026-06-01',
    ): FeeRule {
        return FeeRule::query()->create([
            'code' => $code,
            'name' => str($displayCategory)->headline()->toString(),
            'ledger_category' => $displayCategory === FeeRule::DisplayCategoryDownpayment ? FeeRule::LedgerCategoryDownpayment : FeeRule::LedgerCategoryCharge,
            'display_category' => $displayCategory,
            'program_id' => $programId,
            'term_id' => $termId,
            'calculation_type' => $calculationType,
            'amount' => $amount,
            'rate' => $rate,
            'effective_from' => $effectiveFrom,
            'effective_until' => null,
            'is_active' => true,
            'authority' => 'TAL-68 test fee matrix',
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
