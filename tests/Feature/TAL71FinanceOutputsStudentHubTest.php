<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceEvidenceService;
use App\Actions\Integrations\Payments\MockPaymentGateway;
use App\Actions\Integrations\Payments\PaymentGateway;
use App\Filament\Student\Pages\Finance;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\AssessmentObligation;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\FinancialAccommodation;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL71FinanceOutputsStudentHubTest extends TestCase
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

        $this->app->instance(PaymentGateway::class, new MockPaymentGateway(
            providerName: 'mock',
            checkoutBaseUrl: 'https://mock-payments.test/checkout',
        ));
    }

    public function test_student_finance_page_shows_ledger_derived_finance_and_available_outputs(): void
    {
        $fixture = $this->financeFixture();

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->assertSee('Current Term Account')
            ->assertSee('Tuition obligation')
            ->assertSee('Due through as-of')
            ->assertSee('PHP 8,500.00')
            ->assertSee('Online PayMongo checkout is not active')
            ->assertSee('Manual payment evidence');
    }

    public function test_finance_outputs_are_authenticated_owned_and_logged(): void
    {
        $fixture = $this->financeFixture();
        $student = $fixture['student'];

        $this->actingAs($student)
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()
            ->assertSee('Statement of Account')
            ->assertSee('Tuition obligation')
            ->assertSee('PHP 8,500.00');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputSoa,
            'source_record_type' => Assessment::class,
            'source_record_id' => $fixture['assessment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'actor_user_id' => $student->id,
            'action' => FinanceEvidenceService::ActionView,
            'copy_context' => 'LEARNER_COPY',
            'status' => 'logged',
        ]);

        $this->assertFalse(Route::has('finance.billing-slip'));

        $this->actingAs($student)
            ->get(route('finance.payments.acknowledgement', $fixture['payment']).'?print=1')
            ->assertOk()
            ->assertSee('Payment Acknowledgment')
            ->assertSee('Actual Verified Amount')
            ->assertSee('PHP 500.00');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputPaymentAcknowledgement,
            'source_record_type' => Payment::class,
            'source_record_id' => $fixture['payment']->id,
            'actor_user_id' => $student->id,
            'action' => FinanceEvidenceService::ActionPrint,
        ]);
    }

    public function test_student_cannot_access_another_students_finance_outputs(): void
    {
        $fixture = $this->financeFixture();
        $other = $this->studentUser();
        StudentProfile::factory()->for($other)->create();
        $accessLogCountBefore = DB::table('output_access_logs')->count();

        $this->actingAs($other)
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertForbidden();

        $this->actingAs($other)
            ->get(route('finance.payments.acknowledgement', $fixture['payment']))
            ->assertForbidden();

        $this->assertSame($accessLogCountBefore, DB::table('output_access_logs')->count());
    }

    public function test_retired_billing_slip_is_absent_and_acknowledgement_requires_a_posted_payment(): void
    {
        $fixture = $this->financeFixture([
            'schedule_state' => 'paid',
            'ledger_payment_amount' => '9000.00',
        ]);
        $unpostedPayment = Payment::factory()->for($fixture['profile'])->for($fixture['term'])->create([
            'evidence_status' => 'verified',
            'amount' => '750.00',
            'paid_at' => now(),
            'verified_at' => now(),
        ]);
        $draftLedgerPayment = Payment::factory()->for($fixture['profile'])->for($fixture['term'])->create([
            'evidence_status' => 'verified',
            'amount' => '250.00',
            'paid_at' => now(),
            'verified_at' => now(),
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'direction' => LedgerEntry::DirectionPayment,
            'category' => 'downpayment',
            'amount' => '250.00',
            'source_type' => Payment::class,
            'source_id' => $draftLedgerPayment->id,
            'payment_id' => $draftLedgerPayment->id,
            'description' => 'Draft payment posting',
            'posted_at' => now(),
            'state' => 'draft',
        ]);

        $this->assertFalse(Route::has('finance.billing-slip'));

        $this->actingAs($fixture['student'])
            ->get(route('finance.payments.acknowledgement', $unpostedPayment))
            ->assertForbidden();

        $this->actingAs($fixture['student'])
            ->get(route('finance.payments.acknowledgement', $draftLedgerPayment))
            ->assertForbidden();
    }

    public function test_paymongo_checkout_is_not_active_and_manual_payment_remains_available(): void
    {
        $fixture = $this->financeFixture(['include_review_attempt' => false]);

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->assertSee('Online PayMongo checkout is not active')
            ->assertSee('Manual payment evidence');
        $this->assertSame(0, PaymentAttempt::query()->where('assessment_id', $fixture['assessment']->id)->count());
    }

    public function test_student_hub_replaces_old_finance_placeholder_routes(): void
    {
        $student = $this->studentUser();
        StudentProfile::factory()->for($student)->create();

        $this->actingAs($student);

        $this->get('/student/finance')->assertOk();
        $this->get('/student/soa-view')->assertNotFound();
        $this->get('/student/payment-acknowledgement-view')->assertNotFound();

        $this->assertNotNull(Route::getRoutes()->getByName('finance.statement'));
        $this->assertNull(Route::getRoutes()->getByName('finance.billing-slip'));
        $this->assertNotNull(Route::getRoutes()->getByName('finance.payments.acknowledgement'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,assessment:Assessment,schedule:PaymentScheduleRow,payment:Payment}
     */
    private function financeFixture(array $overrides = []): array
    {
        $student = $this->studentUser();
        $program = Program::factory()->create(['code' => fake()->unique()->bothify('BSBA###')]);
        $profile = StudentProfile::factory()->for($student)->for($program)->create([
            'student_number' => 'SIA-2026-'.fake()->unique()->numerify('####'),
        ]);
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'credential_user_id' => $student->id,
            'status' => 'pending_payment',
            'registered_at' => now()->subDay(),
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $enrollment->id,
            'credential_user_id' => $student->id,
            'term_id' => $term->id,
        ]);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '9000.00',
            'discount_total' => '0.00',
            'total' => '9000.00',
            'required_downpayment' => '2000.00',
            'activated_at' => now(),
        ]);
        $obligation = AssessmentObligation::factory()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'code' => 'TUITION',
            'label' => 'Tuition obligation',
            'purpose' => 'TermPayment',
            'amount' => '9000.00',
            'due_at' => now()->subDay(),
            'required_for_enrollment' => true,
        ]);
        $feeRule = FeeRule::query()->create([
            'code' => 'TUITION',
            'name' => 'Tuition Fee',
            'ledger_category' => FeeRule::LedgerCategoryCharge,
            'display_category' => FeeRule::DisplayCategoryTuition,
            'program_id' => $program->id,
            'term_id' => $term->id,
            'calculation_type' => FeeRule::CalculationFixed,
            'amount' => '9000.00',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
            'authority' => 'TAL-71 fixture',
        ]);
        $line = AssessmentLine::query()->create([
            'assessment_id' => $assessment->id,
            'fee_rule_id' => $feeRule->id,
            'source_line_key' => 'tuition',
            'description_snapshot' => 'Tuition Fee',
            'quantity' => '1.0000',
            'rate' => '9000.00',
            'amount' => '9000.00',
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
            'amount' => '9000.00',
            'source_type' => AssessmentLine::class,
            'source_id' => $line->id,
            'description' => 'Tuition Fee',
            'posted_at' => now()->subHour(),
            'state' => 'posted',
        ]);
        $payment = Payment::factory()->for($profile)->for($term)->create([
            'term_account_id' => $account->id,
            'method' => 'paymongo',
            'channel' => 'paymongo',
            'amount' => $overrides['ledger_payment_amount'] ?? '500.00',
            'evidence_status' => 'verified',
            'paid_at' => now()->subMinutes(30),
            'verified_at' => now()->subMinutes(20),
            'state' => Payment::StatePosted,
            'verification_basis' => 'IndependentSourceCheck',
            'external_check_reference' => 'SYNTH-TAL71-CHECK',
            'provider_reference' => 'pm_'.fake()->unique()->numerify('######'),
            'or_number' => null,
        ]);
        PaymentAllocation::factory()->create([
            'payment_id' => $payment->id,
            'sequence' => 1,
            'assessment_obligation_id' => $obligation->id,
            'assessment_line_id' => null,
            'amount' => $overrides['ledger_payment_amount'] ?? '500.00',
        ]);
        LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionPayment,
            'category' => 'downpayment',
            'amount' => $overrides['ledger_payment_amount'] ?? '500.00',
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'payment_id' => $payment->id,
            'description' => 'Posted payment',
            'posted_at' => now()->subMinutes(10),
            'state' => 'posted',
        ]);
        if (($overrides['include_review_attempt'] ?? true) === true) {
            PaymentAttempt::query()->create([
                'assessment_id' => $assessment->id,
                'student_profile_id' => $profile->id,
                'channel' => 'paymongo',
                'provider' => 'mock',
                'internal_reference' => 'TALA-PAY-'.fake()->unique()->uuid(),
                'amount' => '2000.00',
                'currency' => 'PHP',
                'status' => 'under_review',
                'metadata' => ['note' => 'Fixture review state'],
            ]);
        }
        FinancialAccommodation::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'balance_snapshot' => '8500.00',
            'covered_amount' => '1000.00',
            'basis' => 'INSTITUTIONAL_ACCOMMODATION',
            'promissory_required' => true,
            'promissory_maker' => 'Parent Guardian',
            'allows_finance_gate' => true,
            'waives_downpayment' => false,
            'authority' => 'Accounting Office',
            'status' => FinancialAccommodation::StatusActive,
            'effective_from' => now()->toDateString(),
            'expires_on' => now()->addMonth()->toDateString(),
        ]);

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
