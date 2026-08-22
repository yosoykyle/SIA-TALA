<?php

namespace Tests\Feature;

use App\Actions\Finance\AccountingAdjustmentService;
use App\Models\AccountingAdjustment;
use App\Models\Enrollment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL86CAccountingAdjustmentReversalTest extends TestCase
{
    use DatabaseTransactions;

    private AccountingAdjustmentService $service;

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

        $this->service = app(AccountingAdjustmentService::class);
    }

    public function test_accounting_posts_debit_and_credit_adjustments_using_clean_ledger_schema(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $fixture = $this->ledgerFixture();

        $debit = $this->service->post([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'adjustment_type' => AccountingAdjustment::TypeStudentAccountDebit,
            'amount' => '250.00',
            'reason' => 'Approved manual debit correction for omitted laboratory charge.',
            'evidence_reference' => 'ADJ-86C-DEBIT',
        ], $accounting, CarbonImmutable::parse('2026-06-12 10:00:00'));

        $debitAdjustment = AccountingAdjustment::query()->findOrFail($debit['adjustment_id']);
        $debitLedger = LedgerEntry::query()->findOrFail($debit['ledger_entry_id']);

        $this->assertSame('5550.00', $debit['current_balance']);
        $this->assertSame(AccountingAdjustment::TypeStudentAccountDebit, $debitAdjustment->adjustment_type);
        $this->assertSame(LedgerEntry::DirectionAdjustment, $debitLedger->direction);
        $this->assertSame(AccountingAdjustment::LedgerCategoryAdjustment, $debitLedger->category);
        $this->assertSame('250.00', (string) $debitLedger->amount);
        $this->assertSame(AccountingAdjustment::class, $debitLedger->source_type);
        $this->assertSame($debitAdjustment->id, $debitLedger->source_id);
        $this->assertNull($debitLedger->reverses_entry_id);
        $this->assertNull($debitLedger->adjusts_entry_id);
        $this->assertSame('posted', $debitLedger->state);

        $credit = $this->service->post([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'source_ledger_entry_id' => $fixture['charge']->id,
            'adjustment_type' => AccountingAdjustment::TypeStudentAccountCredit,
            'amount' => '100.00',
            'reason' => 'Approved manual credit correction against assessed charge.',
            'evidence_reference' => 'ADJ-86C-CREDIT',
        ], $accounting, CarbonImmutable::parse('2026-06-12 10:15:00'));

        $creditAdjustment = AccountingAdjustment::query()->findOrFail($credit['adjustment_id']);
        $creditLedger = LedgerEntry::query()->findOrFail($credit['ledger_entry_id']);

        $this->assertSame('5450.00', $credit['current_balance']);
        $this->assertSame(AccountingAdjustment::TypeStudentAccountCredit, $creditAdjustment->adjustment_type);
        $this->assertSame(LedgerEntry::DirectionAdjustment, $creditLedger->direction);
        $this->assertSame('-100.00', (string) $creditLedger->amount);
        $this->assertSame($fixture['charge']->id, $creditLedger->adjusts_entry_id);
        $this->assertNull($creditLedger->reverses_entry_id);
        $this->assertFalse(Schema::hasColumn('ledger_entries', 'entry_type'));
        $this->assertFalse(Schema::hasColumn('ledger_entries', 'running_balance'));
    }

    public function test_accounting_reverses_one_posted_ledger_entry_once(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $fixture = $this->ledgerFixture();

        $summary = $this->service->post([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'source_ledger_entry_id' => $fixture['charge']->id,
            'adjustment_type' => AccountingAdjustment::TypeLedgerEntryReversal,
            'reason' => 'Approved reversal of duplicated active assessment charge.',
            'evidence_reference' => 'REV-86C-CHARGE',
        ], $accounting, CarbonImmutable::parse('2026-06-12 11:00:00'));

        $adjustment = AccountingAdjustment::query()->findOrFail($summary['adjustment_id']);
        $reversal = LedgerEntry::query()->findOrFail($summary['ledger_entry_id']);

        $this->assertSame('-500.00', $summary['current_balance']);
        $this->assertSame(AccountingAdjustment::TypeLedgerEntryReversal, $adjustment->adjustment_type);
        $this->assertSame($fixture['charge']->id, $adjustment->source_ledger_entry_id);
        $this->assertSame($reversal->id, $adjustment->ledger_entry_id);
        $this->assertSame(LedgerEntry::DirectionReversal, $reversal->direction);
        $this->assertSame(AccountingAdjustment::LedgerCategoryReversal, $reversal->category);
        $this->assertSame(AccountingAdjustment::class, $reversal->source_type);
        $this->assertSame($adjustment->id, $reversal->source_id);
        $this->assertSame($fixture['charge']->id, $reversal->reverses_entry_id);
        $this->assertNull($reversal->adjusts_entry_id);
        $this->assertSame('5800.00', (string) $reversal->amount);
        $this->assertSame('-5800.00', $this->balanceEffect($reversal));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Selected ledger entry has already been reversed.');

        try {
            $this->service->post([
                'student_profile_id' => $fixture['profile']->id,
                'term_id' => $fixture['term']->id,
                'enrollment_id' => $fixture['enrollment']->id,
                'source_ledger_entry_id' => $fixture['charge']->id,
                'adjustment_type' => AccountingAdjustment::TypeLedgerEntryReversal,
                'reason' => 'Attempted duplicate reversal should be blocked cleanly.',
            ], $accounting, CarbonImmutable::parse('2026-06-12 11:10:00'));
        } finally {
            $this->assertSame(1, LedgerEntry::query()
                ->where('enrollment_id', $fixture['enrollment']->id)
                ->where('direction', LedgerEntry::DirectionReversal)
                ->count());
            $this->assertSame(1, AccountingAdjustment::query()
                ->where('enrollment_id', $fixture['enrollment']->id)
                ->where('adjustment_type', AccountingAdjustment::TypeLedgerEntryReversal)
                ->count());
        }
    }

    public function test_payment_reversal_uses_negative_amount_to_negate_payment_balance_effect(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $fixture = $this->ledgerFixture();

        $summary = $this->service->post([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'source_ledger_entry_id' => $fixture['payment']->id,
            'adjustment_type' => AccountingAdjustment::TypeLedgerEntryReversal,
            'reason' => 'Approved reversal of incorrectly posted manual payment.',
        ], $accounting, CarbonImmutable::parse('2026-06-12 11:30:00'));

        $reversal = LedgerEntry::query()->findOrFail($summary['ledger_entry_id']);

        $this->assertSame('5800.00', $summary['current_balance']);
        $this->assertSame(LedgerEntry::DirectionReversal, $reversal->direction);
        $this->assertSame('-500.00', (string) $reversal->amount);
        $this->assertSame('500.00', $this->balanceEffect($reversal));
    }

    public function test_zero_amount_future_posting_and_non_accounting_users_are_blocked_without_writes(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixture = $this->ledgerFixture();

        try {
            $this->service->post([
                'student_profile_id' => $fixture['profile']->id,
                'term_id' => $fixture['term']->id,
                'adjustment_type' => AccountingAdjustment::TypeStudentAccountDebit,
                'amount' => '0.00',
                'reason' => 'Zero amount adjustment must be rejected by accounting service.',
            ], $accounting, CarbonImmutable::parse('2026-06-12 12:00:00'));
            $this->fail('Zero amount adjustment should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Accounting adjustment amount must be greater than zero.', $exception->getMessage());
        }

        try {
            $this->service->post([
                'student_profile_id' => $fixture['profile']->id,
                'term_id' => $fixture['term']->id,
                'adjustment_type' => AccountingAdjustment::TypeStudentAccountDebit,
                'amount' => '10.00',
                'reason' => 'Future posting date must be rejected by accounting service.',
            ], $accounting, CarbonImmutable::now(config('app.timezone'))->addDay());
            $this->fail('Future posting date should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Accounting adjustment date cannot be in the future.', $exception->getMessage());
        }

        try {
            $this->service->post([
                'student_profile_id' => $fixture['profile']->id,
                'term_id' => $fixture['term']->id,
                'adjustment_type' => AccountingAdjustment::TypeStudentAccountDebit,
                'amount' => '10.00',
                'reason' => 'Registrar cannot post accounting adjustment records.',
            ], $registrar, CarbonImmutable::parse('2026-06-12 12:15:00'));
            $this->fail('Non-Accounting user should be rejected.');
        } catch (AuthorizationException) {
            $this->assertSame(0, AccountingAdjustment::query()->where('enrollment_id', $fixture['enrollment']->id)->count());
            $this->assertSame(2, LedgerEntry::query()->where('enrollment_id', $fixture['enrollment']->id)->count());
        }
    }

    public function test_filament_create_action_and_labels_use_clean_ledger_fields(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $fixture = $this->ledgerFixture();

        $this->assertStringContainsString('charge', AccountingAdjustment::sourceLedgerOptionLabel($fixture['charge']));
        $this->assertStringContainsString('assessment', AccountingAdjustment::sourceLedgerOptionLabel($fixture['charge']));
        $this->assertStringNotContainsString('Balance:', AccountingAdjustment::sourceLedgerOptionLabel($fixture['charge']));

        $this->assertFalse(Route::has('filament.admin.resources.accounting-adjustments.index'));
        $this->assertFalse(Route::has('filament.admin.resources.accounting-adjustments.create'));
        $this->assertSame(0, AccountingAdjustment::query()->count());
        $this->assertDatabaseHas('ledger_entries', ['id' => $fixture['charge']->id]);
    }

    /**
     * @return array{profile:StudentProfile,term:Term,enrollment:Enrollment,charge:LedgerEntry,payment:LedgerEntry}
     */
    private function ledgerFixture(): array
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

        $charge = LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'assessment',
            'amount' => '5800.00',
            'source_type' => Enrollment::class,
            'source_id' => $enrollment->id,
            'description' => 'Active assessment charge',
            'posted_at' => now()->subHours(2),
            'state' => 'posted',
        ]);

        $payment = Payment::factory()
            ->for($profile)
            ->for($term)
            ->create([
                'method' => 'manual',
                'channel' => 'cash',
                'amount' => '500.00',
                'evidence_status' => 'verified',
                'paid_at' => now()->subHour(),
                'verified_at' => now()->subMinutes(50),
                'provider_reference' => 'manual:86c-'.fake()->unique()->uuid(),
            ]);

        $paymentLedger = LedgerEntry::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'direction' => LedgerEntry::DirectionPayment,
            'category' => 'payment',
            'amount' => '500.00',
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'payment_id' => $payment->id,
            'description' => 'Verified manual payment',
            'posted_at' => now()->subHour(),
            'state' => 'posted',
        ]);

        return [
            'profile' => $profile,
            'term' => $term,
            'enrollment' => $enrollment,
            'charge' => $charge,
            'payment' => $paymentLedger,
        ];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function balanceEffect(LedgerEntry $entry): string
    {
        $money = app(DecimalMoney::class);
        $amount = (string) $entry->amount;

        return match ($entry->direction) {
            LedgerEntry::DirectionPayment,
            LedgerEntry::DirectionDiscount,
            LedgerEntry::DirectionScholarship,
            LedgerEntry::DirectionWaiver,
            LedgerEntry::DirectionReversal => $money->subtract('0.00', $amount),
            default => $money->normalize($amount),
        };
    }
}
