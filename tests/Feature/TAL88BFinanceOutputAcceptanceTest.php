<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceEvidenceService;
use App\Filament\Student\Pages\Finance;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\AssessmentObligation;
use App\Models\Enrollment;
use App\Models\FeeRule;
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
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL88BFinanceOutputAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        foreach (['student', User::StaffRoleAccounting] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_accounting_can_access_all_finance_outputs_with_accounting_copy_logging(): void
    {
        $fixture = $this->financeFixture();
        $accounting = $this->accountingUser();

        $this->actingAs($accounting)
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()
            ->assertSee('Authenticated Account Copy')
            ->assertSee('Tuition obligation');

        $this->actingAs($accounting)
            ->get(route('finance.payments.acknowledgement', $fixture['payment']))
            ->assertOk()
            ->assertSee('Payment Acknowledgment');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputSoa,
            'source_record_type' => Assessment::class,
            'source_record_id' => $fixture['assessment']->id,
            'actor_user_id' => $accounting->id,
            'copy_context' => FinanceEvidenceService::CopyAccounting,
        ]);
        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputPaymentAcknowledgement,
            'source_record_type' => Payment::class,
            'source_record_id' => $fixture['payment']->id,
            'actor_user_id' => $accounting->id,
            'copy_context' => FinanceEvidenceService::CopyAccounting,
        ]);
    }

    public function test_soa_shows_version_dated_obligations_and_configured_address(): void
    {
        config(['institution.address' => '123 Servitech Ave, Metro Manila']);
        $fixture = $this->financeFixture();

        $this->actingAs($fixture['student'])
            ->get(route('finance.statement', $fixture['assessment']).'?print=1')
            ->assertOk()
            ->assertSee('Assessment Version')
            ->assertSee('Dated Obligations')
            ->assertSee('Tuition obligation')
            ->assertSee('123 Servitech Ave, Metro Manila');
    }

    public function test_payment_acknowledgement_humanizes_and_deduplicates_method_and_channel(): void
    {
        $fixture = $this->financeFixture();

        $this->actingAs($fixture['student'])
            ->get(route('finance.payments.acknowledgement', $fixture['payment']))
            ->assertOk()
            ->assertSee('PayMongo')
            ->assertDontSee('PayMongo / PayMongo')
            ->assertDontSee('paymongo / paymongo')
            ->assertSee('not an official receipt or tax document');

        $fixture['payment']->update([
            'method' => 'cash',
            'channel' => 'cash',
        ]);

        $this->actingAs($fixture['student'])
            ->get(route('finance.payments.acknowledgement', $fixture['payment']))
            ->assertOk()
            ->assertSee('Cash')
            ->assertDontSee('Cash / Cash')
            ->assertDontSee('cash / cash')
            ->assertSee('not an official receipt or tax document');
    }

    public function test_student_blocked_from_superseded_soa_but_accounting_allowed(): void
    {
        $fixture = $this->financeFixture(['assessment_state' => Assessment::StateSuperseded]);

        $this->actingAs($fixture['student'])
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertForbidden();

        $this->actingAs($this->accountingUser())
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()
            ->assertSee('Historical');
    }

    public function test_student_finance_reports_payment_under_review_status(): void
    {
        $fixture = $this->financeFixture();
        PaymentAttempt::query()->create([
            'assessment_id' => $fixture['assessment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'channel' => 'paymongo',
            'provider' => 'mock',
            'internal_reference' => 'TALA-PAY-'.fake()->unique()->uuid(),
            'amount' => '2000.00',
            'currency' => 'PHP',
            'status' => 'under_review',
        ]);

        $finance = app(FinanceEvidenceService::class)->studentFinance($fixture['student']);

        $this->assertTrue($finance['available']);
        $this->assertSame('Payment Under Review', $finance['summary']['payment_status']);

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->assertSee('Online checkout')
            ->assertSee('Manual payment evidence');
    }

    public function test_student_finance_reports_payment_rejected_when_latest_attempt_failed_without_posted_payment(): void
    {
        $fixture = $this->financeFixture(['post_payment' => false]);
        PaymentAttempt::query()->create([
            'assessment_id' => $fixture['assessment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'channel' => 'paymongo',
            'provider' => 'mock',
            'internal_reference' => 'TALA-PAY-'.fake()->unique()->uuid(),
            'amount' => '2000.00',
            'currency' => 'PHP',
            'status' => 'failed',
        ]);

        $finance = app(FinanceEvidenceService::class)->studentFinance($fixture['student']);

        $this->assertTrue($finance['available']);
        $this->assertSame('Payment Rejected', $finance['summary']['payment_status']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,assessment:Assessment,schedule:PaymentScheduleRow,payment:Payment}
     */
    private function financeFixture(array $overrides = []): array
    {
        $postPayment = $overrides['post_payment'] ?? true;
        $student = $this->studentUser();
        $program = Program::factory()->create(['code' => fake()->unique()->bothify('BSBA###')]);
        $profile = StudentProfile::factory()->for($student)->for($program)->create([
            'student_number' => 'SIA-2026-'.fake()->unique()->numerify('####'),
        ]);
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'credential_user_id' => $student->id,
            'status' => 'officially_enrolled',
            'registered_at' => now()->subDay(),
            'officially_enrolled_at' => now(),
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
            'state' => $overrides['assessment_state'] ?? Assessment::StateActive,
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
            'authority' => 'TAL-88B fixture',
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
            'state' => PaymentScheduleRow::StateDue,
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
            'amount' => '500.00',
            'evidence_status' => 'verified',
            'paid_at' => now()->subMinutes(30),
            'verified_at' => now()->subMinutes(20),
            'state' => $postPayment ? Payment::StatePosted : 'Exception',
            'verification_basis' => $postPayment ? 'IndependentSourceCheck' : null,
            'external_check_reference' => $postPayment ? 'SYNTH-TAL88B-CHECK' : null,
            'provider_reference' => 'pm_'.fake()->unique()->numerify('######'),
            'or_number' => null,
        ]);

        if ($postPayment) {
            PaymentAllocation::factory()->create([
                'payment_id' => $payment->id,
                'sequence' => 1,
                'assessment_obligation_id' => $obligation->id,
                'assessment_line_id' => null,
                'amount' => '500.00',
            ]);
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

    private function accountingUser(): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole(User::StaffRoleAccounting);

        return $user;
    }
}
