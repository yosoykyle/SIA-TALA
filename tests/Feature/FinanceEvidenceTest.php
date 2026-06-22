<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceEvidenceService;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_statement_and_confirmed_payment_acknowledgement(): void
    {
        [$student, $enrollment, $payment] = $this->financeRecords();

        $this->actingAs($student)
            ->get(route('finance.statements.show', $enrollment))
            ->assertOk()
            ->assertSee('Statement of Account')
            ->assertSee('PHP 1,000.00')
            ->assertSee('PHP 250.00')
            ->assertSee('PHP 750.00')
            ->assertSee(FinanceEvidenceService::EVIDENCE_DISCLAIMER);

        $this->actingAs($student)
            ->get(route('finance.payments.acknowledgement', $payment))
            ->assertOk()
            ->assertSee('Payment Acknowledgement')
            ->assertSee('PAY-EVIDENCE-001')
            ->assertSee('PHP 250.00')
            ->assertSee(FinanceEvidenceService::EVIDENCE_DISCLAIMER);
    }

    public function test_finance_staff_can_view_evidence_but_unrelated_student_cannot(): void
    {
        [, $enrollment, $payment] = $this->financeRecords();
        $accounting = $this->userWithPermissions(['create-assessments', 'process-payments']);

        $this->actingAs($accounting)
            ->get(route('finance.statements.show', $enrollment))
            ->assertOk();

        $this->actingAs($accounting)
            ->get(route('finance.payments.acknowledgement', $payment))
            ->assertOk();

        $unrelatedStudent = User::factory()->create();

        $this->actingAs($unrelatedStudent)
            ->get(route('finance.statements.show', $enrollment))
            ->assertForbidden();

        $this->actingAs($unrelatedStudent)
            ->get(route('finance.payments.acknowledgement', $payment))
            ->assertForbidden();
    }

    public function test_voided_payment_cannot_generate_an_acknowledgement(): void
    {
        [$student, , $payment] = $this->financeRecords();
        $payment->update(['status' => 'voided']);

        $this->actingAs($student)
            ->get(route('finance.payments.acknowledgement', $payment))
            ->assertForbidden();
    }

    /**
     * @return array{User, Enrollment, Payment}
     */
    private function financeRecords(): array
    {
        $student = User::factory()->create();
        $term = Term::factory()->create();
        $profile = StudentProfile::factory()->create([
            'user_id' => $student->id,
            'current_balance' => '750.00',
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
        ]);

        LedgerEntry::factory()->create([
            'student_profile_id' => $profile->id,
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'entry_type' => 'assessment',
            'amount' => '1000.00',
            'running_balance' => '1000.00',
            'posted_at' => now()->subMinute(),
        ]);
        $paymentEntry = LedgerEntry::factory()->create([
            'student_profile_id' => $profile->id,
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'entry_type' => 'payment',
            'amount' => '-250.00',
            'running_balance' => '750.00',
            'posted_at' => now(),
        ]);
        $payment = Payment::factory()->create([
            'student_profile_id' => $profile->id,
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'ledger_entry_id' => $paymentEntry->id,
            'payment_reference' => 'PAY-EVIDENCE-001',
            'amount' => '250.00',
            'status' => 'confirmed',
        ]);

        return [$student, $enrollment, $payment];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission));
        }

        return $user;
    }
}
