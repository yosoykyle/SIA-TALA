<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceEvidenceService;
use App\Actions\Integrations\Payments\CreatePaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentCheckoutRequest;
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
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

        Permission::query()->firstOrCreate(['name' => 'process-payments', 'guard_name' => 'web']);
        Role::query()->where('name', User::StaffRoleAccounting)->firstOrFail()->givePermissionTo('process-payments');
    }

    public function test_student_finance_page_shows_ledger_derived_finance_and_available_outputs(): void
    {
        $fixture = $this->financeFixture();

        Livewire::actingAs($fixture['student'])
            ->test(Finance::class)
            ->assertSee('Available')
            ->assertSee('Tuition Fee')
            ->assertSee('Required Downpayment')
            ->assertSee('Current Amount Due')
            ->assertSee('PHP 2,000.00')
            ->assertSee('PHP 8,500.00')
            ->assertSee('Pending OR Mapping')
            ->assertSee('Institutional Accommodation');
    }

    public function test_finance_outputs_are_authenticated_owned_and_logged(): void
    {
        $fixture = $this->financeFixture();
        $student = $fixture['student'];

        $this->actingAs($student)
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()
            ->assertSee('Statement of Account')
            ->assertSee('Tuition Fee')
            ->assertSee('PHP 8,500.00');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputSoa,
            'source_record_type' => Assessment::class,
            'source_record_id' => $fixture['assessment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'actor_user_id' => $student->id,
            'action' => FinanceEvidenceService::ActionView,
            'copy_context' => FinanceEvidenceService::CopyStudent,
            'status' => 'logged',
        ]);

        $this->actingAs($student)
            ->get(route('finance.billing-slip', $fixture['assessment']).'?print=1')
            ->assertOk()
            ->assertSee('Billing Slip')
            ->assertSee('not an official tax receipt')
            ->assertSee('PHP 2,000.00');

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputBillingSlip,
            'source_record_type' => PaymentScheduleRow::class,
            'source_record_id' => $fixture['schedule']->id,
            'actor_user_id' => $student->id,
            'action' => FinanceEvidenceService::ActionPrint,
        ]);

        $this->actingAs($student)
            ->get(route('finance.payments.acknowledgement', $fixture['payment']).'?print=1')
            ->assertOk()
            ->assertSee('Payment Acknowledgement')
            ->assertSee('Pending OR Mapping')
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

        $this->actingAs($other)
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertForbidden();

        $this->actingAs($other)
            ->get(route('finance.billing-slip', $fixture['assessment']))
            ->assertForbidden();

        $this->actingAs($other)
            ->get(route('finance.payments.acknowledgement', $fixture['payment']))
            ->assertForbidden();

        $this->assertSame(0, DB::table('output_access_logs')->count());
    }

    public function test_billing_slip_and_acknowledgement_are_unavailable_without_required_source_state(): void
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

        $this->actingAs($fixture['student'])
            ->get(route('finance.billing-slip', $fixture['assessment']))
            ->assertForbidden();

        $this->actingAs($fixture['student'])
            ->get(route('finance.payments.acknowledgement', $unpostedPayment))
            ->assertForbidden();

        $this->actingAs($fixture['student'])
            ->get(route('finance.payments.acknowledgement', $draftLedgerPayment))
            ->assertForbidden();
    }

    public function test_checkout_uses_current_due_amount_and_reuses_matching_pending_attempt(): void
    {
        $fixture = $this->financeFixture();
        $creator = app(CreatePaymentCheckoutSession::class);

        $first = $creator->create(new PaymentCheckoutRequest(
            studentProfileId: $fixture['profile']->id,
            amount: '2000.00',
            description: 'Current amount due',
            assessmentId: $fixture['assessment']->id,
        ));

        $second = $creator->create(new PaymentCheckoutRequest(
            studentProfileId: $fixture['profile']->id,
            amount: '2000.00',
            description: 'Current amount due',
            assessmentId: $fixture['assessment']->id,
        ));

        $this->assertSame($first['payment_attempt_id'], $second['payment_attempt_id']);
        $this->assertSame(1, PaymentAttempt::query()->where('assessment_id', $fixture['assessment']->id)->where('status', 'pending')->count());
        $this->assertStringStartsWith('https://mock-payments.test/checkout/', $first['checkout_url']);

        $this->expectExceptionMessage('Payment checkout assessment does not belong to the selected student.');

        $creator->create(new PaymentCheckoutRequest(
            studentProfileId: StudentProfile::factory()->create()->id,
            amount: '2000.00',
            description: 'Invalid owner',
            assessmentId: $fixture['assessment']->id,
        ));
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
        $this->assertNotNull(Route::getRoutes()->getByName('finance.billing-slip'));
        $this->assertNotNull(Route::getRoutes()->getByName('finance.payments.acknowledgement'));
    }

    /**
     * @param  array<string, string>  $overrides
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
            'status' => 'pending_payment',
            'registered_at' => now()->subDay(),
        ]);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
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
            'method' => 'paymongo',
            'channel' => 'paymongo',
            'amount' => $overrides['ledger_payment_amount'] ?? '500.00',
            'evidence_status' => 'verified',
            'paid_at' => now()->subMinutes(30),
            'verified_at' => now()->subMinutes(20),
            'provider_reference' => 'pm_'.fake()->unique()->numerify('######'),
            'or_number' => null,
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
