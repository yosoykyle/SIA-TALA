<?php

namespace Tests\Feature;

use App\Actions\Cor\BuildCorOutput;
use App\Actions\Finance\FinanceEvidenceService;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL88DCrossRoleOutputRegressionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());

        foreach ([
            'student',
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_cor_print_route_preserves_cross_role_access_and_logging(): void
    {
        $fixture = $this->officialOutputFixture();
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $otherStudent = $this->studentUser();
        StudentProfile::factory()->for($otherStudent)->create();

        $this->get(route('cor.print', $fixture['enrollment']))
            ->assertRedirect(route('filament.student.auth.login'));

        $this->actingAs($fixture['student'])
            ->get(route('cor.print', $fixture['enrollment']))
            ->assertOk()
            ->assertSee('Student Copy');

        $this->actingAs($registrar)
            ->get(route('cor.print', $fixture['enrollment']))
            ->assertOk()
            ->assertSee('Registrar Copy');

        $this->actingAs($accounting)
            ->get(route('cor.print', $fixture['enrollment']))
            ->assertOk()
            ->assertSee('Accounting Copy');

        $logCount = DB::table('output_access_logs')->count();

        $this->actingAs($otherStudent)
            ->get(route('cor.print', $fixture['enrollment']))
            ->assertForbidden();

        $this->actingAs($faculty)
            ->get(route('cor.print', $fixture['enrollment']))
            ->assertForbidden();

        $this->assertSame($logCount, DB::table('output_access_logs')->count());

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => BuildCorOutput::OutputType,
            'source_record_type' => Enrollment::class,
            'source_record_id' => $fixture['enrollment']->id,
            'actor_user_id' => $fixture['student']->id,
            'action' => BuildCorOutput::ActionPrint,
            'copy_context' => BuildCorOutput::CopyStudent,
        ]);
        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => BuildCorOutput::OutputType,
            'source_record_type' => Enrollment::class,
            'source_record_id' => $fixture['enrollment']->id,
            'actor_user_id' => $registrar->id,
            'action' => BuildCorOutput::ActionPrint,
            'copy_context' => BuildCorOutput::CopyRegistrar,
        ]);
        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => BuildCorOutput::OutputType,
            'source_record_type' => Enrollment::class,
            'source_record_id' => $fixture['enrollment']->id,
            'actor_user_id' => $accounting->id,
            'action' => BuildCorOutput::ActionPrint,
            'copy_context' => BuildCorOutput::CopyAccounting,
        ]);
    }

    public function test_finance_output_routes_preserve_accounting_student_access_and_block_other_roles(): void
    {
        $fixture = $this->officialOutputFixture();
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $otherStudent = $this->studentUser();
        StudentProfile::factory()->for($otherStudent)->create();

        foreach ($this->financeOutputUrls($fixture) as $url) {
            $this->get($url)
                ->assertRedirect(route('filament.student.auth.login'));
        }

        $this->actingAs($fixture['student'])
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()
            ->assertSee('Student Copy');

        $this->actingAs($fixture['student'])
            ->get(route('finance.billing-slip', $fixture['assessment']).'?print=1')
            ->assertOk()
            ->assertSee('Billing Slip');

        $this->actingAs($fixture['student'])
            ->get(route('finance.payments.acknowledgement', $fixture['payment']).'?print=1')
            ->assertOk()
            ->assertSee('Payment Acknowledgement');

        $this->actingAs($accounting)
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()
            ->assertSee('Accounting Copy');

        $this->actingAs($accounting)
            ->get(route('finance.billing-slip', $fixture['assessment']).'?print=1')
            ->assertOk()
            ->assertSee('Billing Slip');

        $this->actingAs($accounting)
            ->get(route('finance.payments.acknowledgement', $fixture['payment']).'?print=1')
            ->assertOk()
            ->assertSee('Payment Acknowledgement');

        $logCount = DB::table('output_access_logs')->count();

        foreach ([$otherStudent, $registrar, $faculty] as $blockedActor) {
            foreach ($this->financeOutputUrls($fixture) as $url) {
                $this->actingAs($blockedActor)
                    ->get($url)
                    ->assertForbidden();
            }
        }

        $this->assertSame($logCount, DB::table('output_access_logs')->count());

        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputSoa,
            'source_record_type' => Assessment::class,
            'source_record_id' => $fixture['assessment']->id,
            'actor_user_id' => $fixture['student']->id,
            'action' => FinanceEvidenceService::ActionView,
            'copy_context' => FinanceEvidenceService::CopyStudent,
        ]);
        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputBillingSlip,
            'source_record_type' => PaymentScheduleRow::class,
            'source_record_id' => $fixture['schedule']->id,
            'actor_user_id' => $fixture['student']->id,
            'action' => FinanceEvidenceService::ActionPrint,
            'copy_context' => FinanceEvidenceService::CopyStudent,
        ]);
        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputPaymentAcknowledgement,
            'source_record_type' => Payment::class,
            'source_record_id' => $fixture['payment']->id,
            'actor_user_id' => $fixture['student']->id,
            'action' => FinanceEvidenceService::ActionPrint,
            'copy_context' => FinanceEvidenceService::CopyStudent,
        ]);
        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputSoa,
            'source_record_type' => Assessment::class,
            'source_record_id' => $fixture['assessment']->id,
            'actor_user_id' => $accounting->id,
            'copy_context' => FinanceEvidenceService::CopyAccounting,
        ]);
        $this->assertDatabaseHas('output_access_logs', [
            'output_type' => FinanceEvidenceService::OutputBillingSlip,
            'source_record_type' => PaymentScheduleRow::class,
            'source_record_id' => $fixture['schedule']->id,
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

    /**
     * @param  array{assessment: Assessment, payment: Payment}  $fixture
     * @return list<string>
     */
    private function financeOutputUrls(array $fixture): array
    {
        return [
            route('finance.statement', $fixture['assessment']),
            route('finance.billing-slip', $fixture['assessment']).'?print=1',
            route('finance.payments.acknowledgement', $fixture['payment']).'?print=1',
        ];
    }

    /**
     * @return array{student: User, profile: StudentProfile, term: Term, enrollment: Enrollment, assessment: Assessment, schedule: PaymentScheduleRow, payment: Payment}
     */
    private function officialOutputFixture(): array
    {
        $student = $this->studentUser();
        $program = Program::factory()->create([
            'code' => fake()->unique()->bothify('BSTM###'),
            'name' => 'BS Tourism Management',
        ]);
        $profile = StudentProfile::factory()->for($student)->for($program)->create([
            'student_number' => 'SIA-2026-'.fake()->unique()->numerify('####'),
        ]);
        $term = Term::factory()->create([
            'label' => 'First Semester 2026-2027',
            'state' => Term::StateActive,
        ]);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'officially_enrolled',
            'registered_at' => now()->subDay(),
            'officially_enrolled_at' => now(),
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
            'authority' => 'TAL-88D fixture',
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

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
