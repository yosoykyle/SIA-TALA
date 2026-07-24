<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceEvidenceService;
use App\Filament\Student\Pages\Finance;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\FinancialAccommodation;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL91BStudentHubFinanceProjectionAcceptanceTest extends TestCase
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

        foreach (['student', User::StaffRoleAccounting] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function test_student_finance_page_hides_accommodation_staff_only_fields(): void
    {
        $fixture = $this->financeFixture();

        $certificationReference = 'CERT-REF-'.fake()->unique()->numerify('#######');
        $privateEvidenceReference = 'PRIVATE-EVIDENCE-'.fake()->unique()->numerify('#######');
        $promissoryMaker = 'Promissory Maker Jane Q. Doe';
        $authority = 'Decision Authority Board Resolution 2026-XYZ';
        $recorder = User::factory()->create(['status' => User::StatusActive]);
        $recorder->assignRole(User::StaffRoleAccounting);

        FinancialAccommodation::query()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'balance_snapshot' => '8500.00',
            'covered_amount' => '1000.00',
            'basis' => FinancialAccommodation::BasisDswDLguCertification,
            'certification_reference' => $certificationReference,
            'private_evidence_reference' => $privateEvidenceReference,
            'promissory_required' => true,
            'promissory_maker' => $promissoryMaker,
            'allows_finance_gate' => true,
            'waives_downpayment' => false,
            'authority' => $authority,
            'recorded_by' => $recorder->id,
            'status' => FinancialAccommodation::StatusActive,
            'effective_from' => now()->toDateString(),
            'expires_on' => now()->addMonth()->toDateString(),
        ]);

        $component = Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->assertSee('DSWD/LGU Certification')
            ->assertSee('PHP 1,000.00')
            ->assertSee('Current-term Finance Gate');

        $component
            ->assertDontSee($certificationReference)
            ->assertDontSee($privateEvidenceReference)
            ->assertDontSee($promissoryMaker)
            ->assertDontSee($authority)
            ->assertDontSee($recorder->name);
    }

    #[Test]
    public function test_finance_page_projection_never_leaks_another_students_assessment_or_accommodation(): void
    {
        $fixtureA = $this->financeFixture([
            'student_number' => 'SIA-2026-1001',
            'program_code' => 'BSBAA1',
            'program_name' => 'BS Business Administration A',
            'assessment_total' => '9000.00',
            'accommodation_basis' => FinancialAccommodation::BasisInstitutionalAccommodation,
        ]);

        $fixtureB = $this->financeFixture([
            'student_number' => 'SIA-2026-2002',
            'program_code' => 'BSITB2',
            'program_name' => 'BS Information Technology B',
            'assessment_total' => '15750.00',
            'accommodation_basis' => FinancialAccommodation::BasisDswDLguCertification,
        ]);

        $studentBName = $fixtureB['student']->name;
        $studentBNumber = $fixtureB['profile']->student_number;

        Livewire::actingAs($fixtureA['student'])
            ->test(Finance::class)
            ->assertSee($fixtureA['profile']->student_number)
            ->assertDontSee($studentBNumber)
            ->assertDontSee($studentBName)
            ->assertDontSee('PHP 15,750.00')
            ->assertDontSee('DSWD/LGU Certification');
    }

    #[Test]
    public function test_finance_page_distinguishes_payment_checkout_evidence_review_ledger_posted_and_or_mapping_states(): void
    {
        $fixture = $this->financeFixture(['post_payment' => false]);

        $pendingReference = 'TALA-PAY-PENDING-'.fake()->unique()->uuid();
        PaymentAttempt::query()->create([
            'assessment_id' => $fixture['assessment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'channel' => 'paymongo',
            'provider' => 'mock',
            'internal_reference' => $pendingReference,
            'amount' => '2000.00',
            'currency' => 'PHP',
            'status' => 'pending',
        ]);

        $underReviewPayment = Payment::factory()->for($fixture['profile'])->for($fixture['term'])->create([
            'method' => 'paymongo',
            'channel' => 'paymongo',
            'amount' => '300.00',
            'evidence_status' => 'under_review',
            'paid_at' => now()->subMinutes(15),
            'provider_reference' => 'pm_'.fake()->unique()->numerify('######'),
            'or_number' => null,
        ]);

        $postedUnmappedPayment = Payment::factory()->for($fixture['profile'])->for($fixture['term'])->create([
            'method' => 'paymongo',
            'channel' => 'paymongo',
            'amount' => '500.00',
            'evidence_status' => 'verified',
            'paid_at' => now()->subMinutes(30),
            'verified_at' => now()->subMinutes(20),
            'provider_reference' => 'pm_'.fake()->unique()->numerify('######'),
            'or_number' => null,
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'direction' => LedgerEntry::DirectionPayment,
            'category' => 'downpayment',
            'amount' => '500.00',
            'source_type' => Payment::class,
            'source_id' => $postedUnmappedPayment->id,
            'payment_id' => $postedUnmappedPayment->id,
            'description' => 'Posted payment awaiting OR mapping',
            'posted_at' => now()->subMinutes(10),
            'state' => 'posted',
        ]);

        $finance = app(FinanceEvidenceService::class)->studentFinance($fixture['student']);

        $this->assertTrue($finance['available']);

        // (a) checkout status: a pending PaymentAttempt is distinctly recorded.
        $attemptStatuses = collect($finance['state']['attempt_rows'])->pluck('status')->all();
        $this->assertContains('Pending', $attemptStatuses);

        // (b) evidence review status: a payment with evidence_status = under_review exists and is reflected
        // in the summary payment_status (the resolver prioritises under-review evidence).
        $this->assertSame('Payment Under Review', $finance['summary']['payment_status']);
        $this->assertTrue(
            $finance['payments']->contains(fn (Payment $payment): bool => $payment->id === $underReviewPayment->id
                && $payment->evidence_status === 'under_review'),
        );

        // (c) ledger-posted status: a posted LedgerEntry with direction = payment exists and is a distinct row.
        $ledgerDirections = collect($finance['state']['ledger_rows'])->pluck('direction')->all();
        $this->assertContains('Payment', $ledgerDirections);

        // (d) OR mapping pending vs mapped: the posted payment has no or_number, so mapping is pending.
        $this->assertSame('Pending OR Mapping', $finance['summary']['or_mapping_state']);
        $this->assertSame('Payment Posted', $finance['state']['payment_evidence']['headline']);
        $this->assertSame('Posted', $finance['state']['payment_evidence']['ledger_state']);
        $this->assertSame('Pending OR Mapping', $finance['state']['payment_evidence']['or_mapping_state']);
        $this->assertSame('Accounting', $finance['state']['payment_evidence']['responsible_office']);
        $this->assertStringContainsString('OR mapping', $finance['state']['payment_evidence']['required_action']);

        // Confirm all four states are rendered as distinct, separately-labeled values on the page itself
        // while OR mapping is still pending.
        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->assertSee('Payment Under Review')
            ->assertSee('Pending')
            ->assertSee('Payment')
            ->assertSee('Pending OR Mapping')
            ->assertSee('What to do next')
            ->assertSee('Responsible Office');

        // Now map the OR number and confirm the mapped state becomes distinct from the pending state,
        // both in the service projection and in the rendered page.
        $postedUnmappedPayment->update(['or_number' => 'OR-2026-000123']);
        $mappedFinance = app(FinanceEvidenceService::class)->studentFinance($fixture['student']);
        $this->assertSame('Mapped OR OR-2026-000123', $mappedFinance['summary']['or_mapping_state']);
        $this->assertSame('Mapped OR OR-2026-000123', $mappedFinance['state']['payment_evidence']['or_mapping_state']);
        $this->assertNotSame($finance['summary']['or_mapping_state'], $mappedFinance['summary']['or_mapping_state']);

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->assertSee('Mapped OR OR-2026-000123')
            ->assertDontSee('Pending OR Mapping');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,assessment:Assessment,schedule:PaymentScheduleRow,payment:Payment}
     */
    private function financeFixture(array $overrides = []): array
    {
        $postPayment = $overrides['post_payment'] ?? true;
        $student = $this->studentUser();
        $program = Program::factory()->create([
            'code' => $overrides['program_code'] ?? fake()->unique()->bothify('BSBA###'),
            'name' => $overrides['program_name'] ?? 'BS Business Administration',
        ]);
        $profile = StudentProfile::factory()->for($student)->for($program)->create([
            'student_number' => $overrides['student_number'] ?? 'SIA-2026-'.fake()->unique()->numerify('####'),
        ]);
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'pending_payment',
            'registered_at' => now()->subDay(),
        ]);
        $assessmentTotal = $overrides['assessment_total'] ?? '9000.00';
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => $assessmentTotal,
            'discount_total' => '0.00',
            'total' => $assessmentTotal,
            'required_downpayment' => '2000.00',
            'activated_at' => now(),
        ]);
        $feeRule = FeeRule::query()->create([
            'code' => 'TUITION',
            'name' => 'Tuition Fee',
            'ledger_category' => FeeRule::LedgerCategoryCharge,
            'display_category' => FeeRule::DisplayCategoryTuition,
            'program_id' => $program->id,
            'term_id' => $term->id,
            'calculation_type' => FeeRule::CalculationFixed,
            'amount' => $assessmentTotal,
            'effective_from' => now()->toDateString(),
            'is_active' => true,
            'authority' => 'TAL-91B fixture',
        ]);
        $line = AssessmentLine::query()->create([
            'assessment_id' => $assessment->id,
            'fee_rule_id' => $feeRule->id,
            'source_line_key' => 'tuition',
            'description_snapshot' => 'Tuition Fee',
            'quantity' => '1.0000',
            'rate' => $assessmentTotal,
            'amount' => $assessmentTotal,
            'line_type' => 'tuition',
        ]);
        $schedule = PaymentScheduleRow::query()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'category' => PaymentScheduleRow::CategoryDownpayment,
            'due_date' => now()->addWeek()->toDateString(),
            'amount' => '2000.00',
            'state' => $overrides['schedule_state'] ?? PaymentScheduleRow::StateDue,
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'tuition',
            'amount' => $assessmentTotal,
            'source_type' => AssessmentLine::class,
            'source_id' => $line->id,
            'description' => 'Tuition Fee',
            'posted_at' => now()->subHour(),
            'state' => 'posted',
        ]);
        $payment = Payment::factory()->for($profile)->for($term)->create([
            'method' => 'paymongo',
            'channel' => 'paymongo',
            'amount' => '500.00',
            'evidence_status' => 'verified',
            'paid_at' => now()->subMinutes(30),
            'verified_at' => now()->subMinutes(20),
            'provider_reference' => 'pm_'.fake()->unique()->numerify('######'),
            'or_number' => null,
        ]);

        if ($postPayment) {
            LedgerEntry::query()->create([
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'direction' => LedgerEntry::DirectionPayment,
                'category' => 'downpayment',
                'amount' => '500.00',
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'payment_id' => $payment->id,
                'description' => 'Posted payment',
                'posted_at' => now()->subMinutes(10),
                'state' => 'posted',
            ]);
        }

        if (array_key_exists('accommodation_basis', $overrides)) {
            FinancialAccommodation::query()->create([
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'balance_snapshot' => $assessmentTotal,
                'covered_amount' => '1000.00',
                'basis' => $overrides['accommodation_basis'],
                'promissory_required' => false,
                'allows_finance_gate' => true,
                'waives_downpayment' => false,
                'authority' => 'Accounting Office',
                'status' => FinancialAccommodation::StatusActive,
                'effective_from' => now()->toDateString(),
                'expires_on' => now()->addMonth()->toDateString(),
            ]);
        }

        return [
            'student' => $student,
            'profile' => $profile,
            'term' => $term,
            'enrollment' => $enrollment,
            'assessment' => $assessment,
            'schedule' => $schedule,
            'payment' => $payment,
        ];
    }

    private function studentUser(): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('student');

        return $user;
    }
}
