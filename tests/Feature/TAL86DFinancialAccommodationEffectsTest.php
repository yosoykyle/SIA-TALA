<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceEvidenceService;
use App\Actions\StudentLifecycle\HoldEvaluationService;
use App\Filament\Resources\FinancialAccommodations\Pages\CreateFinancialAccommodation;
use App\Filament\Resources\FinancialAccommodations\Pages\ListFinancialAccommodations;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\FinancialAccommodation;
use App\Models\Hold;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL86DFinancialAccommodationEffectsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_test_codex', DB::connection()->getDatabaseName());

        foreach ([
            'student',
            User::StaffRoleAccounting,
            User::StaffRoleRegistrar,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_accounting_records_approved_accommodation_result_with_schedule_and_append_only_policy(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->enrollmentFixture();

        $this->assertTrue(Gate::forUser($accounting)->allows('create', FinancialAccommodation::class));
        $this->assertFalse(Gate::forUser($registrar)->allows('create', FinancialAccommodation::class));
        $this->assertNotNull(Route::getRoutes()->getByName('filament.admin.resources.financial-accommodations.index'));

        Livewire::actingAs($accounting)
            ->test(CreateFinancialAccommodation::class)
            ->fillForm([
                'student_profile_id' => $fixture['profile']->id,
                'term_id' => $fixture['term']->id,
                'balance_snapshot' => '8500.00',
                'covered_amount' => '2500.00',
                'basis' => FinancialAccommodation::BasisInstitutionalAccommodation,
                'certification_reference' => 'CERT-86D-PRIVATE',
                'private_evidence_reference' => 'locked-cabinet/file-86d.pdf',
                'promissory_required' => true,
                'promissory_maker' => 'Parent Guardian',
                'allows_finance_gate' => true,
                'allows_next_term_enrollment' => false,
                'allows_reactivation' => false,
                'allows_record_release' => true,
                'waives_downpayment' => true,
                'authority' => 'Accounting Director',
                'status' => FinancialAccommodation::StatusActive,
                'effective_from' => today()->toDateString(),
                'expires_on' => today()->addMonth()->toDateString(),
                'paymentScheduleRows' => [
                    [
                        'sequence' => 1,
                        'category' => PaymentScheduleRow::CategoryDownpayment,
                        'due_date' => today()->addWeeks(2)->toDateString(),
                        'amount' => '1250.00',
                        'state' => PaymentScheduleRow::StateDue,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $accommodation = FinancialAccommodation::query()
            ->where('student_profile_id', $fixture['profile']->id)
            ->where('term_id', $fixture['term']->id)
            ->sole();
        $schedule = PaymentScheduleRow::query()
            ->where('financial_accommodation_id', $accommodation->id)
            ->sole();

        $this->assertSame($fixture['profile']->id, $accommodation->student_profile_id);
        $this->assertSame($fixture['term']->id, $accommodation->term_id);
        $this->assertSame($accounting->id, $accommodation->recorded_by);
        $this->assertSame(FinancialAccommodation::StatusActive, $accommodation->status);
        $this->assertSame('locked-cabinet/file-86d.pdf', $accommodation->private_evidence_reference);
        $this->assertTrue((bool) $accommodation->allows_finance_gate);
        $this->assertTrue((bool) $accommodation->allows_record_release);
        $this->assertSame($accommodation->id, $schedule->financial_accommodation_id);
        $this->assertNull($schedule->assessment_id);

        $this->assertFalse(Gate::forUser($accounting)->allows('update', $accommodation));
        $this->assertFalse(Gate::forUser($accounting)->allows('delete', $accommodation));

        Livewire::actingAs($accounting)
            ->test(ListFinancialAccommodations::class)
            ->assertCanSeeTableRecords([$accommodation]);
    }

    public function test_active_valid_accommodation_bypasses_only_explicit_financial_hold_effects(): void
    {
        $fixture = $this->enrollmentFixture();

        $financial = Hold::factory()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        $documentary = Hold::factory()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'hold_type' => Hold::TypeDocumentary,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        $recordRelease = Hold::factory()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingRecordRelease,
        ]);

        $this->accommodationFor($fixture, [
            'allows_finance_gate' => true,
            'allows_record_release' => false,
        ]);

        $remaining = app(HoldEvaluationService::class)->activeBlockingHolds(
            $fixture['profile'],
            [Hold::BlockingEnrollment, Hold::BlockingRecordRelease],
            $fixture['enrollment'],
        );

        $this->assertFalse($remaining->contains('id', $financial->id));
        $this->assertTrue($remaining->contains('id', $documentary->id));
        $this->assertTrue($remaining->contains('id', $recordRelease->id));
    }

    public function test_wrong_term_expired_inactive_and_missing_effect_accommodations_do_not_bypass_finance_hold(): void
    {
        $service = app(HoldEvaluationService::class);

        $wrongTerm = $this->enrollmentFixture();
        Hold::factory()->create([
            'student_profile_id' => $wrongTerm['profile']->id,
            'term_id' => $wrongTerm['term']->id,
            'enrollment_id' => $wrongTerm['enrollment']->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        $this->accommodationFor($wrongTerm, [
            'term_id' => Term::factory()->create()->id,
            'allows_finance_gate' => true,
        ]);
        $this->assertTrue($service->hasActiveBlockingHold($wrongTerm['profile'], [Hold::BlockingEnrollment], $wrongTerm['enrollment']));

        $expired = $this->enrollmentFixture();
        Hold::factory()->create([
            'student_profile_id' => $expired['profile']->id,
            'term_id' => $expired['term']->id,
            'enrollment_id' => $expired['enrollment']->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        $this->accommodationFor($expired, [
            'allows_finance_gate' => true,
            'effective_from' => today()->subMonths(2),
            'expires_on' => today()->subDay(),
        ]);
        $this->assertTrue($service->hasActiveBlockingHold($expired['profile'], [Hold::BlockingEnrollment], $expired['enrollment']));

        $inactive = $this->enrollmentFixture();
        Hold::factory()->create([
            'student_profile_id' => $inactive['profile']->id,
            'term_id' => $inactive['term']->id,
            'enrollment_id' => $inactive['enrollment']->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        $this->accommodationFor($inactive, [
            'allows_finance_gate' => true,
            'status' => FinancialAccommodation::StatusCancelled,
        ]);
        $this->assertTrue($service->hasActiveBlockingHold($inactive['profile'], [Hold::BlockingEnrollment], $inactive['enrollment']));

        $missingEffect = $this->enrollmentFixture();
        Hold::factory()->create([
            'student_profile_id' => $missingEffect['profile']->id,
            'term_id' => $missingEffect['term']->id,
            'enrollment_id' => $missingEffect['enrollment']->id,
            'hold_type' => Hold::TypeFinancial,
            'blocking_level' => Hold::BlockingEnrollment,
        ]);
        $this->accommodationFor($missingEffect, [
            'allows_finance_gate' => false,
            'allows_record_release' => true,
        ]);
        $this->assertTrue($service->hasActiveBlockingHold($missingEffect['profile'], [Hold::BlockingEnrollment], $missingEffect['enrollment']));
    }

    public function test_student_finance_summary_shows_safe_effects_without_private_promissory_or_certification_evidence(): void
    {
        $fixture = $this->financeFixture();

        $this->accommodationFor($fixture, [
            'covered_amount' => '3000.00',
            'basis' => FinancialAccommodation::BasisDswDLguCertification,
            'certification_reference' => 'CERT-PRIVATE-86D',
            'private_evidence_reference' => 'vault/private/promissory-86d.pdf',
            'promissory_required' => true,
            'promissory_maker' => 'Private Maker Name',
            'allows_finance_gate' => true,
            'allows_next_term_enrollment' => true,
            'allows_reactivation' => false,
            'allows_record_release' => false,
            'waives_downpayment' => true,
        ]);
        PaymentScheduleRow::query()->create([
            'financial_accommodation_id' => FinancialAccommodation::query()->latest('id')->value('id'),
            'sequence' => 1,
            'category' => PaymentScheduleRow::CategoryDownpayment,
            'due_date' => today()->addWeek()->toDateString(),
            'amount' => '1500.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);

        $finance = app(FinanceEvidenceService::class)->financeForAssessment($fixture['assessment'], $fixture['student']);
        $summary = $finance['state']['accommodation_summary'];
        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);

        $this->assertSame('Active', $summary['status']);
        $this->assertSame('PHP 3,000.00', $summary['covered_amount']);
        $this->assertContains('Current-term Finance Gate', $summary['approved_effects']);
        $this->assertContains('Next-term Enrollment', $summary['approved_effects']);
        $this->assertContains('Downpayment Waived', $summary['approved_effects']);
        $this->assertStringNotContainsString('CERT-PRIVATE-86D', $encoded);
        $this->assertStringNotContainsString('vault/private/promissory-86d.pdf', $encoded);
        $this->assertStringNotContainsString('Private Maker Name', $encoded);
    }

    /**
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment}
     */
    private function enrollmentFixture(): array
    {
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $student->assignRole('student');

        $program = Program::factory()->create();
        $profile = StudentProfile::factory()
            ->for($student)
            ->for($program)
            ->create();
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['status' => 'pending_payment']);

        return [
            'student' => $student,
            'profile' => $profile,
            'term' => $term,
            'enrollment' => $enrollment,
        ];
    }

    /**
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,assessment:Assessment}
     */
    private function financeFixture(): array
    {
        $fixture = $this->enrollmentFixture();

        $assessment = Assessment::query()->create([
            'enrollment_id' => $fixture['enrollment']->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '9000.00',
            'discount_total' => '0.00',
            'total' => '9000.00',
            'required_downpayment' => '2000.00',
            'activated_at' => now(),
        ]);
        $feeRule = FeeRule::query()->create([
            'code' => 'TUITION',
            'name' => 'Tuition Fee',
            'ledger_category' => FeeRule::LedgerCategoryCharge,
            'display_category' => FeeRule::DisplayCategoryTuition,
            'program_id' => $fixture['profile']->program_id,
            'term_id' => $fixture['term']->id,
            'calculation_type' => FeeRule::CalculationFixed,
            'amount' => '9000.00',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
            'authority' => 'TAL-86D fixture',
        ]);
        AssessmentLine::query()->create([
            'assessment_id' => $assessment->id,
            'fee_rule_id' => $feeRule->id,
            'source_line_key' => 'tuition',
            'description_snapshot' => 'Tuition Fee',
            'quantity' => '1.0000',
            'rate' => '9000.00',
            'amount' => '9000.00',
            'line_type' => 'tuition',
        ]);

        return [
            ...$fixture,
            'assessment' => $assessment,
        ];
    }

    /**
     * @param  array{profile:StudentProfile,term:Term}  $fixture
     * @param  array<string, mixed>  $overrides
     */
    private function accommodationFor(array $fixture, array $overrides = []): FinancialAccommodation
    {
        return FinancialAccommodation::query()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'balance_snapshot' => '8500.00',
            'covered_amount' => '1000.00',
            'basis' => FinancialAccommodation::BasisInstitutionalAccommodation,
            'certification_reference' => null,
            'private_evidence_reference' => null,
            'promissory_required' => false,
            'promissory_maker' => null,
            'allows_finance_gate' => false,
            'allows_next_term_enrollment' => false,
            'allows_reactivation' => false,
            'allows_record_release' => false,
            'waives_downpayment' => false,
            'authority' => 'Accounting Director',
            'status' => FinancialAccommodation::StatusActive,
            'effective_from' => today(),
            'expires_on' => today()->addMonth(),
            ...$overrides,
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
