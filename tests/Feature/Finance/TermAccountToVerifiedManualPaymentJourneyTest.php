<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\CreateContextualFinanceExport;
use App\Actions\Finance\CreateFeePlanDraft;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Finance\RecordApprovedCoverage;
use App\Actions\Finance\ReverseApprovedCoverage;
use App\Actions\Finance\ReversePaymentPosting;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\Integrations\Payments\PaymentPostedNotificationService;
use App\Mail\PaymentPostedMail;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FinanceExport;
use App\Models\OfficialOutputPaymentClearance;
use App\Models\OperationalEvent;
use App\Models\OutputAccessLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentEvidenceVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TermAccountToVerifiedManualPaymentJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        Role::query()->firstOrCreate([
            'name' => User::StaffRoleAccounting,
            'guard_name' => 'web',
        ]);
    }

    public function test_fee_plan_uses_independent_reconciled_obligations_with_due_dates(): void
    {
        $accounting = $this->accounting();
        $program = Program::factory()->create();
        $term = Term::factory()->create();

        $draft = app(CreateFeePlanDraft::class)->execute(
            $program,
            $term,
            [
                ['code' => 'TUITION', 'label' => 'Tuition', 'category' => 'Tuition', 'amount' => '36000.00'],
                ['code' => 'MISC', 'label' => 'Miscellaneous fees', 'category' => 'InstitutionalFee', 'amount' => '12000.00'],
            ],
            $accounting,
            obligations: [
                ['code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'purpose' => 'Enrollment', 'amount' => '12000.00', 'due_at' => '2026-08-25 17:00:00', 'required_for_enrollment' => true],
                ['code' => 'MIDTERM', 'label' => 'Midterm obligation', 'purpose' => 'TermPayment', 'amount' => '18000.00', 'due_at' => '2026-10-15 17:00:00'],
                ['code' => 'FINAL', 'label' => 'Final obligation', 'purpose' => 'TermPayment', 'amount' => '18000.00', 'due_at' => '2026-12-01 17:00:00'],
            ],
        );

        $published = app(PublishFeePlan::class)->execute(
            $draft,
            $accounting,
            'SYNTH-FEE-AUTHORITY-2026-01',
            CarbonImmutable::parse('2026-08-01', config('app.timezone')),
        );

        $this->assertSame(FeePlan::StatePublished, $published->state);
        $this->assertSame('48000.00', number_format((float) $published->obligations->sum('amount'), 2, '.', ''));
        $this->assertSame([1, 2, 3], $published->obligations->pluck('sequence')->all());
        $this->assertSame('2026-08-01', $published->authority_date->toDateString());
    }

    public function test_current_due_excludes_later_obligations_until_their_due_time(): void
    {
        $fixture = $this->accountFixture();
        $asOf = CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone'));

        $position = app(TermAccountProjection::class)->forAccount($fixture['account'], $asOf);

        $this->assertSame('12000.00', $position['current_due']);
        $this->assertSame('48000.00', $position['remaining_balance']);
        $this->assertSame('ActionNeeded', $position['state']);
        $this->assertSame('ENROLLMENT', $position['next_obligation']['code']);
    }

    public function test_verified_actual_amount_is_applied_oldest_due_first_and_retry_is_idempotent(): void
    {
        Mail::fake();
        $fixture = $this->accountFixture();
        $evidence = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'claimed_amount' => '12000.00',
            'channel' => 'gcash_manual',
            'paid_at' => CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
            'payment_reference' => 'GCASH-SYNTH-0001',
            'submitted_by' => $fixture['learner']->id,
            'submitted_at' => CarbonImmutable::parse('2026-08-25 18:05:00', config('app.timezone')),
        ]);

        $first = app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $fixture['accounting'],
            '7000.00',
            'SYNTH-BANK-CHECK-0001',
        );
        $retry = app(ReviewPaymentEvidence::class)->verify(
            $evidence->fresh(),
            $fixture['accounting'],
            '7000.00',
            'SYNTH-BANK-CHECK-0001',
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame('7000.00', $first->amount);
        $this->assertSame(1, Payment::query()->where('payment_evidence_version_id', $evidence->id)->count());
        $this->assertSame('7000.00', PaymentAllocation::query()
            ->where('payment_id', $first->id)
            ->where('assessment_obligation_id', $fixture['obligations'][0]->id)
            ->sum('amount'));
    }

    public function test_payment_notification_is_after_commit_and_duplicate_safe_for_the_account_owner(): void
    {
        Mail::fake();
        $fixture = $this->accountFixture();
        $evidence = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'claimed_amount' => '12000.00',
            'channel' => 'bank_transfer',
            'paid_at' => CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
            'payment_reference' => 'BANK-SYNTH-NOTIFICATION',
            'submitted_by' => $fixture['learner']->id,
        ]);
        $payment = app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $fixture['accounting'],
            '12000.00',
            'SYNTH-BANK-CHECK-NOTIFICATION',
        );

        $service = app(PaymentPostedNotificationService::class);
        $service->record($payment);
        $service->record($payment->fresh());

        $this->assertSame(1, OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
            ->where('related_record_id', $payment->id)
            ->count());
        Mail::assertQueued(PaymentPostedMail::class, function (PaymentPostedMail $mail) use ($fixture): bool {
            $this->assertTrue($mail->afterCommit);
            $this->assertTrue($mail->hasTo($fixture['learner']->email));

            return true;
        });
        Mail::assertQueuedCount(1);
    }

    public function test_notification_queue_failure_does_not_roll_back_the_financial_posting(): void
    {
        $fixture = $this->accountFixture();
        $payment = Payment::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'student_profile_id' => null,
            'term_id' => $fixture['account']->term_id,
            'state' => Payment::StatePosted,
            'amount' => '12000.00',
            'channel' => 'bank_transfer',
            'paid_at' => CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
            'provider_reference' => 'SYNTH-NOTIFICATION-FAILURE',
        ]);

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('Sensitive transport failure'));
        app(PaymentPostedNotificationService::class)->record($payment);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'state' => Payment::StatePosted,
            'amount' => '12000.00',
        ]);
        $event = OperationalEvent::query()
            ->where('event_type', OperationalEvent::TypePaymentPostedEmail)
            ->where('related_record_id', $payment->id)
            ->sole();
        $this->assertSame(OperationalEvent::StatusFailed, $event->status);
        $this->assertSame('Mail could not be queued.', data_get($event->diagnostics, 'reason'));
        $this->assertStringNotContainsString('Sensitive transport failure', json_encode($event->diagnostics, JSON_THROW_ON_ERROR));
    }

    public function test_excess_verified_amount_remains_an_exception_without_partial_posting(): void
    {
        $fixture = $this->accountFixture();
        $evidence = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'claimed_amount' => '50000.00',
            'channel' => 'bank_transfer',
            'paid_at' => CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
            'payment_reference' => 'BANK-SYNTH-EXCESS',
            'submitted_by' => $fixture['learner']->id,
        ]);

        try {
            app(ReviewPaymentEvidence::class)->verify(
                $evidence,
                $fixture['accounting'],
                '50000.00',
                'SYNTH-BANK-CHECK-EXCESS',
            );
            $this->fail('Excess verified money must remain an exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('actual_verified_amount', $exception->errors());
        }

        $this->assertFalse(Payment::query()->where('payment_evidence_version_id', $evidence->id)->exists());
        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $evidence->fresh()->state);
    }

    public function test_coverage_is_bounded_and_reversal_restores_the_exact_due(): void
    {
        $fixture = $this->accountFixture();
        $obligation = $fixture['obligations'][0];
        $coverage = app(RecordApprovedCoverage::class)->execute(
            $fixture['account'],
            $obligation,
            [
                'category' => ApprovedCoverage::CategoryGovernmentSubsidy,
                'safe_source_description' => 'Synthetic government subsidy result',
                'authority_reference' => 'SYNTH-COV-AUTH-0001',
                'authority_date' => '2026-08-20',
                'effective_date' => '2026-08-25',
                'amount' => '2000.00',
            ],
            $fixture['accounting'],
        );

        $covered = app(TermAccountProjection::class)->forAccount(
            $fixture['account'],
            CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
        );
        $this->assertSame('10000.00', $covered['current_due']);

        app(ReverseApprovedCoverage::class)->execute(
            $coverage,
            $fixture['accounting'],
            'SYNTH-COV-REVERSAL-0001',
            'External authority withdrew the result.',
        );

        $reversed = app(TermAccountProjection::class)->forAccount(
            $fixture['account'],
            CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
        );
        $this->assertSame('12000.00', $reversed['current_due']);
        $this->assertSame(ApprovedCoverage::StateReversed, $coverage->fresh()->state);
    }

    public function test_payment_reversal_is_append_only_and_restores_exact_obligation_effects(): void
    {
        $fixture = $this->accountFixture();
        $evidence = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'claimed_amount' => '3000.00',
            'channel' => 'bank_transfer',
            'paid_at' => CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
            'payment_reference' => 'BANK-SYNTH-REVERSAL',
            'submitted_by' => $fixture['learner']->id,
        ]);
        $payment = app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $fixture['accounting'],
            '3000.00',
            'SYNTH-BANK-CHECK-REVERSAL',
        );

        $reversal = app(ReversePaymentPosting::class)->execute(
            $payment,
            $fixture['accounting'],
            'SYNTH-PAYMENT-REVERSAL-0001',
            'Verified external correction.',
        );

        $this->assertSame($payment->id, $reversal->reverses_payment_id);
        $this->assertSame('3000.00', $reversal->amount);
        $this->assertSame('3000.00', $payment->fresh()->amount);
        $this->assertSame(
            $payment->allocations()->pluck('assessment_obligation_id')->all(),
            $reversal->allocations()->pluck('assessment_obligation_id')->all(),
        );
    }

    public function test_contextual_export_is_private_bounded_and_spreadsheet_safe(): void
    {
        Storage::fake('local');
        $fixture = $this->accountFixture();
        $export = app(CreateContextualFinanceExport::class)->createAccountStatus(
            $fixture['accounting'],
            'Reconcile the synthetic Term Account.',
            new Collection([$fixture['account']->enrollment]),
            ['term_account_id' => $fixture['account']->id],
            CarbonImmutable::parse('2026-08-25 18:00:00', config('app.timezone')),
        );

        Storage::disk('local')->assertExists($export->path);
        $contents = Storage::disk('local')->get($export->path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $this->assertSame(FinanceExport::TypeAccountStatus, $export->type);
        $this->actingAs($fixture['accounting'])->get(route('finance.exports.download', $export))->assertOk();
        $this->actingAs($fixture['learner'])->get(route('finance.exports.download', $export))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('finance.exports.download', $export))->assertForbidden();

        $this->expectException(ValidationException::class);
        app(CreateContextualFinanceExport::class)->createAccountStatus(
            $fixture['accounting'], 'Too broad',
            new Collection(array_fill(0, 10001, $fixture['account']->enrollment)),
            [], CarbonImmutable::now(config('app.timezone')),
        );
    }

    public function test_tor_payment_clearance_is_request_specific_versioned_and_not_global(): void
    {
        $fixture = $this->accountFixture();
        $first = OfficialOutputPaymentClearance::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'output_request_reference' => 'TOR-SYNTH-0001',
            'version' => 1,
            'state' => OfficialOutputPaymentClearance::StateNotCleared,
            'authority_reference' => 'SYNTH-CLEARANCE-AUTH-1',
            'safe_reason' => 'Historical current due remained.',
            'decided_by' => $fixture['accounting']->id,
            'decided_at' => now(),
        ]);
        $second = OfficialOutputPaymentClearance::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'output_request_reference' => 'TOR-SYNTH-0001',
            'version' => 2,
            'supersedes_clearance_id' => $first->id,
            'state' => OfficialOutputPaymentClearance::StateCleared,
            'authority_reference' => 'SYNTH-CLEARANCE-AUTH-2',
            'safe_reason' => 'Historical request-specific decision retained.',
            'decided_by' => $fixture['accounting']->id,
            'decided_at' => now(),
        ]);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->supersedes_clearance_id);
        $this->assertSame(TermAccount::StateOpen, $fixture['account']->fresh()->state);
        $this->assertFalse(OfficialOutputPaymentClearance::query()->where('output_request_reference', 'TOR-SYNTH-OTHER')->exists());
    }

    public function test_private_payment_evidence_is_versioned_and_submission_never_posts_money(): void
    {
        Storage::fake('local');
        $fixture = $this->accountFixture();
        $first = app(SubmitPaymentEvidence::class)->execute(
            $fixture['account'], $fixture['learner'], UploadedFile::fake()->image('proof.png'),
            '2500.00', 'gcash_manual', now()->subMinute(), 'SYN-GCASH-PRIVATE-001',
        );
        $second = app(SubmitPaymentEvidence::class)->execute(
            $fixture['account'], $fixture['learner'], UploadedFile::fake()->image('replacement.png', 24, 24),
            '2500.00', 'gcash_manual', now()->subMinute(), 'SYN-GCASH-PRIVATE-002',
        );

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->supersedes_version_id);
        $this->assertFalse(Payment::query()->where('term_account_id', $fixture['account']->id)->exists());
        $this->actingAs($fixture['learner'])->get(route('finance.payment-evidence.download', $second))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('finance.payment-evidence.download', $second))->assertForbidden();
    }

    public function test_soa_and_acknowledgement_use_canonical_state_and_record_access(): void
    {
        Mail::fake();
        $fixture = $this->accountFixture();
        $evidence = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['account']->id, 'claimed_amount' => '3000.00',
            'channel' => 'bank_transfer', 'paid_at' => '2026-08-25 18:00:00',
            'payment_reference' => 'BANK-SYNTH-OUTPUT', 'submitted_by' => $fixture['learner']->id,
        ]);
        $payment = app(ReviewPaymentEvidence::class)->verify(
            $evidence, $fixture['accounting'], '3000.00', 'SYN-BANK-CHECK-OUTPUT',
        );

        $this->actingAs($fixture['learner'])
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()->assertSee('Due Through As-of')->assertDontSee('Ledger Entry');
        $this->actingAs($fixture['learner'])
            ->get(route('finance.payments.acknowledgement', $payment))
            ->assertOk()->assertSee('Actual Verified Amount')->assertSee('Obligation Effects');

        $this->assertSame(1, OutputAccessLog::query()->where('output_type', 'SOA')->where('source_record_id', $fixture['assessment']->id)->count());
        $this->assertSame(1, OutputAccessLog::query()->where('output_type', 'PAYMENT_ACKNOWLEDGEMENT')->where('source_record_id', $payment->id)->count());
        $this->actingAs(User::factory()->create())->get(route('finance.statement', $fixture['assessment']))->assertForbidden();
    }

    public function test_coverage_supersession_preserves_history_and_revalidates_the_bound(): void
    {
        $fixture = $this->accountFixture();
        $base = [
            'category' => ApprovedCoverage::CategoryScholarship,
            'safe_source_description' => 'Synthetic scholarship decision',
            'authority_reference' => 'SYN-COV-BASE', 'authority_date' => '2026-08-01',
            'effective_date' => '2026-08-25', 'amount' => '2000.00',
        ];
        $first = app(RecordApprovedCoverage::class)->execute($fixture['account'], $fixture['obligations'][0], $base, $fixture['accounting']);
        $second = app(RecordApprovedCoverage::class)->execute(
            $fixture['account'], $fixture['obligations'][0],
            [...$base, 'authority_reference' => 'SYN-COV-SUCCESSOR', 'amount' => '3000.00', 'supersedes_coverage_id' => $first->id],
            $fixture['accounting'],
        );

        $this->assertSame(ApprovedCoverage::StateSuperseded, $first->fresh()->state);
        $this->assertSame($first->id, $second->supersedes_coverage_id);
        $this->assertSame(2, ApprovedCoverage::query()->where('term_account_id', $fixture['account']->id)->count());

        $this->expectException(ValidationException::class);
        app(RecordApprovedCoverage::class)->execute(
            $fixture['account'], $fixture['obligations'][0], [...$base, 'amount' => '10000.01'], $fixture['accounting'],
        );
    }

    public function test_legacy_finance_routes_and_generic_reports_are_unreachable_while_manual_payment_remains_visible(): void
    {
        $fixture = $this->accountFixture();
        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $fixture['learner']->assignRole('student');
        $profile = StudentProfile::factory()->create(['user_id' => $fixture['learner']->id]);
        $fixture['account']->enrollment->update(['student_profile_id' => $profile->id]);

        $this->assertFalse(Route::has('finance.billing-slip'));
        $this->assertFalse(Route::has('filament.admin.pages.paymongo-reconciliation'));
        $this->assertFileDoesNotExist(app_path('Actions/Reports/OperationalReportService.php'));

        $this->actingAs($fixture['learner'])
            ->get(route('filament.student.pages.finance'))
            ->assertOk()
            ->assertSee('Manual payment evidence')
            ->assertSee('Online checkout')
            ->assertDontSee('Billing Slip')
            ->assertDontSee('Financial Accommodation');
    }

    /** @return array{accounting:User,learner:User,account:TermAccount,assessment:Assessment,obligations:list<AssessmentObligation>} */
    private function accountFixture(): array
    {
        $accounting = $this->accounting();
        $learner = User::factory()->create(['status' => User::StatusActive]);
        Role::query()->firstOrCreate(['name' => 'applicant', 'guard_name' => 'web']);
        $learner->assignRole('applicant');
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => null,
            'credential_user_id' => $learner->id,
            'term_id' => $term->id,
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $enrollment->id,
            'credential_user_id' => $learner->id,
            'term_id' => $term->id,
        ]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'source_proposal_version_id' => null,
            'total' => '48000.00',
            'subtotal' => '48000.00',
        ]);
        $obligations = [
            AssessmentObligation::factory()->create([
                'assessment_id' => $assessment->id,
                'sequence' => 1,
                'code' => 'ENROLLMENT',
                'label' => 'Enrollment obligation',
                'purpose' => 'Enrollment',
                'amount' => '12000.00',
                'due_at' => '2026-08-25 17:00:00',
                'required_for_enrollment' => true,
            ]),
            AssessmentObligation::factory()->create([
                'assessment_id' => $assessment->id,
                'sequence' => 2,
                'code' => 'MIDTERM',
                'label' => 'Midterm obligation',
                'purpose' => 'TermPayment',
                'amount' => '18000.00',
                'due_at' => '2026-10-15 17:00:00',
                'required_for_enrollment' => false,
            ]),
            AssessmentObligation::factory()->create([
                'assessment_id' => $assessment->id,
                'sequence' => 3,
                'code' => 'FINAL',
                'label' => 'Final obligation',
                'purpose' => 'TermPayment',
                'amount' => '18000.00',
                'due_at' => '2026-12-01 17:00:00',
                'required_for_enrollment' => false,
            ]),
        ];

        return compact('accounting', 'learner', 'account', 'assessment', 'obligations');
    }

    private function accounting(): User
    {
        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);

        return $accounting;
    }
}
