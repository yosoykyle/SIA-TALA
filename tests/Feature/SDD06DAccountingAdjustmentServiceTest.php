<?php

namespace Tests\Feature;

use App\Actions\Finance\AccountingAdjustmentService;
use App\Models\AccountingAdjustment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SDD06DAccountingAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_authorized_accounting_users_can_post_adjustments(): void
    {
        [$enrollment, $studentProfile] = $this->adjustmentContext();
        $unauthorizedUser = User::factory()->create();

        try {
            app(AccountingAdjustmentService::class)->post([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $enrollment->term_id,
                'enrollment_id' => $enrollment->id,
                'adjustment_type' => AccountingAdjustment::TypeStudentAccountDebit,
                'amount' => '100.00',
                'reason' => 'Documented correction request.',
            ], $unauthorizedUser);

            $this->fail('Unauthorized accounting adjustment should throw.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only authorized Accounting users can post accounting adjustments.', $exception->getMessage());
        }

        $this->assertDatabaseCount(AccountingAdjustment::class, 0);
        $this->assertSame(1, LedgerEntry::query()->count());
        $this->assertSame('1000.00', $studentProfile->fresh()->current_balance);
    }

    public function test_debit_adjustment_posts_immutable_ledger_balance_and_audit_evidence(): void
    {
        [$enrollment, $studentProfile, $sourceLedgerEntry, $accounting] = $this->adjustmentContext();
        $postedAt = CarbonImmutable::now(config('app.timezone'))->subDay()->startOfMinute();

        $summary = app(AccountingAdjustmentService::class)->post([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'source_ledger_entry_id' => $sourceLedgerEntry->id,
            'adjustment_type' => AccountingAdjustment::TypeStudentAccountDebit,
            'amount' => '125.50',
            'reason' => 'Assessment understatement corrected after receipt review.',
            'evidence_reference' => 'Receipt log page 12',
        ], $accounting, $postedAt);

        $adjustment = AccountingAdjustment::query()->findOrFail($summary['adjustment_id']);
        $ledgerEntry = LedgerEntry::query()->findOrFail($summary['ledger_entry_id']);

        $this->assertSame(AccountingAdjustment::TypeStudentAccountDebit, $adjustment->adjustment_type);
        $this->assertSame('125.50', $adjustment->amount);
        $this->assertSame($sourceLedgerEntry->id, $adjustment->source_ledger_entry_id);
        $this->assertSame($ledgerEntry->id, $adjustment->ledger_entry_id);
        $this->assertSame('accounting_adjustment', $ledgerEntry->entry_type);
        $this->assertSame('accounting_adjustment', $ledgerEntry->reference_type);
        $this->assertSame($adjustment->id, $ledgerEntry->reference_id);
        $this->assertSame('125.50', $ledgerEntry->amount);
        $this->assertSame('1125.50', $ledgerEntry->running_balance);
        $this->assertSame('1125.50', $summary['current_balance']);
        $this->assertSame('1125.50', $studentProfile->fresh()->current_balance);
        $this->assertTrue($adjustment->posted_at->equalTo($postedAt));
        $this->assertDatabaseCount(Payment::class, 0);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => AccountingAdjustment::class,
            'subject_id' => $adjustment->id,
            'event' => 'accounting_adjustment_posted',
        ]);
    }

    public function test_credit_adjustment_reduces_balance_without_creating_payment_or_refund(): void
    {
        [$enrollment, $studentProfile, $sourceLedgerEntry, $accounting] = $this->adjustmentContext();

        $summary = app(AccountingAdjustmentService::class)->post([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'adjustment_type' => AccountingAdjustment::TypeStudentAccountCredit,
            'amount' => '75.25',
            'reason' => 'Manual balance reduction from verified accounting correction.',
            'evidence_reference' => 'Approved correction memo',
        ], $accounting);

        $this->assertSame('assessment', $sourceLedgerEntry->entry_type);

        $ledgerEntry = LedgerEntry::query()->findOrFail($summary['ledger_entry_id']);

        $this->assertSame('-75.25', $ledgerEntry->amount);
        $this->assertSame('924.75', $ledgerEntry->running_balance);
        $this->assertSame('924.75', $studentProfile->fresh()->current_balance);
        $this->assertDatabaseCount(Payment::class, 0);
        $this->assertDatabaseMissing(LedgerEntry::class, [
            'entry_type' => 'refund',
        ]);
    }

    public function test_reversal_posts_exact_opposite_of_source_entry_and_blocks_duplicate_reversal(): void
    {
        [$enrollment, $studentProfile, $paymentLedgerEntry, $accounting] = $this->adjustmentContext(
            sourceEntryType: 'payment',
            sourceAmount: '-150.00',
            currentBalance: '850.00',
        );
        $service = app(AccountingAdjustmentService::class);

        $summary = $service->post([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'source_ledger_entry_id' => $paymentLedgerEntry->id,
            'adjustment_type' => AccountingAdjustment::TypeLedgerEntryReversal,
            'reason' => 'Payment ledger entry reversed after duplicate receipt verification.',
            'evidence_reference' => 'Duplicate receipt review',
        ], $accounting);

        $ledgerEntry = LedgerEntry::query()->findOrFail($summary['ledger_entry_id']);

        $this->assertSame('150.00', $ledgerEntry->amount);
        $this->assertSame('1000.00', $ledgerEntry->running_balance);
        $this->assertSame('1000.00', $studentProfile->fresh()->current_balance);
        $this->assertDatabaseHas(AccountingAdjustment::class, [
            'source_ledger_entry_id' => $paymentLedgerEntry->id,
            'adjustment_type' => AccountingAdjustment::TypeLedgerEntryReversal,
            'amount' => '150.00',
        ]);

        try {
            $service->post([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $enrollment->term_id,
                'enrollment_id' => $enrollment->id,
                'source_ledger_entry_id' => $paymentLedgerEntry->id,
                'adjustment_type' => AccountingAdjustment::TypeLedgerEntryReversal,
                'reason' => 'Second reversal attempt should be rejected.',
            ], $accounting);

            $this->fail('A duplicate reversal should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Selected ledger entry has already been reversed.', $exception->getMessage());
        }

        $this->assertDatabaseCount(AccountingAdjustment::class, 1);
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_adjustment_rejects_source_ledger_entries_outside_selected_student_or_scope(): void
    {
        [$enrollment, $studentProfile, $sourceLedgerEntry, $accounting] = $this->adjustmentContext();
        [$otherEnrollment, $otherStudentProfile, $otherSourceLedgerEntry] = $this->adjustmentContext();

        try {
            app(AccountingAdjustmentService::class)->post([
                'student_profile_id' => $studentProfile->id,
                'term_id' => $enrollment->term_id,
                'enrollment_id' => $enrollment->id,
                'source_ledger_entry_id' => $otherSourceLedgerEntry->id,
                'adjustment_type' => AccountingAdjustment::TypeLedgerEntryReversal,
                'reason' => 'Cross student reversal attempt must be rejected.',
            ], $accounting);

            $this->fail('A cross-student source ledger entry should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Source ledger entry must belong to the selected student.', $exception->getMessage());
        }

        $this->assertDatabaseCount(AccountingAdjustment::class, 0);
        $this->assertSame('1000.00', $studentProfile->fresh()->current_balance);
        $this->assertSame('1000.00', $otherStudentProfile->fresh()->current_balance);
        $this->assertSame($enrollment->id, $sourceLedgerEntry->enrollment_id);
        $this->assertSame($otherEnrollment->id, $otherSourceLedgerEntry->enrollment_id);
    }

    public function test_adjustment_does_not_silently_revoke_finance_handover_or_student_access(): void
    {
        [$enrollment, $studentProfile, $sourceLedgerEntry, $accounting] = $this->adjustmentContext(currentBalance: '0.00');
        $enrollment->forceFill(['status' => 'pre_enrolled'])->save();

        app(AccountingAdjustmentService::class)->post([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $enrollment->term_id,
            'enrollment_id' => $enrollment->id,
            'adjustment_type' => AccountingAdjustment::TypeStudentAccountDebit,
            'amount' => '100.00',
            'reason' => 'Balance reopened after finance handover review.',
        ], $accounting);

        $this->assertSame('pre_enrolled', $enrollment->fresh()->status);
        $this->assertSame('100.00', $studentProfile->fresh()->current_balance);
        $this->assertSame($enrollment->id, $sourceLedgerEntry->enrollment_id);
    }

    /**
     * @return array{Enrollment, StudentProfile, LedgerEntry, User}
     */
    private function adjustmentContext(
        string $sourceEntryType = 'assessment',
        string $sourceAmount = '1000.00',
        string $currentBalance = '1000.00',
    ): array {
        $term = Term::factory()->create();
        $studentProfile = StudentProfile::factory()->create([
            'current_balance' => $currentBalance,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'status' => 'pending_payment',
        ]);
        $sourceLedgerEntry = LedgerEntry::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'entry_type' => $sourceEntryType,
            'reference_type' => $sourceEntryType === 'payment' ? 'payment' : 'fee_template',
            'description' => $sourceEntryType === 'payment' ? 'Accounting-confirmed payment' : 'Tuition assessment',
            'amount' => $sourceAmount,
            'running_balance' => $currentBalance,
        ]);

        return [$enrollment, $studentProfile, $sourceLedgerEntry, $this->accountingUser()];
    }

    private function accountingUser(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $accounting = User::factory()->create();
        $accounting->givePermissionTo(Permission::findOrCreate('post-accounting-adjustments'));

        return $accounting;
    }
}
