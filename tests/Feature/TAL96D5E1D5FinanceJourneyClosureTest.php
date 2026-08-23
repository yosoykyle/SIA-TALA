<?php

namespace Tests\Feature;

use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\FinanceEvidenceService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Finance\StudentAccountPresenter;
use App\Actions\Integrations\Payments\ExactDuePaymentSnapshotService;
use App\Actions\Integrations\Payments\PayMongoPaymentPostingService;
use App\Filament\Resources\Assessments\AssessmentResource;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Widgets\StaffRoleWorkspaceOverviewWidget;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\AssessmentObligation;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvidenceVersion;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL96D5E1D5FinanceJourneyClosureTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_manual_bank_transfer_evidence_posts_to_an_exact_obligation_without_an_or(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $obligation = $fixture['assessment']->obligations()->orderBy('id')->firstOrFail();
        $evidence = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['assessment']->term_account_id,
            'claimed_amount' => '600.00',
            'channel' => 'bank_transfer',
            'paid_at' => now()->subMinute(),
            'submitted_by' => $fixture['profile']->user_id,
        ]);

        $payment = app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $fixture['accounting'],
            '600.00',
            'SYN-INDEPENDENT-CHECK-MANUAL',
        );

        $this->assertNull($payment->or_number);
        $this->assertSame('bank_transfer', $payment->channel);
        $this->assertSame($fixture['assessment']->term_account_id, $payment->term_account_id);
        $this->assertSame('600.00', PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->where('assessment_obligation_id', $obligation->id)
            ->sum('amount'));
    }

    public function test_cash_requires_a_physical_or_number_before_posting(): void
    {
        $fixture = $this->activeAssessmentFixture();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Official Receipt number is required for cash payments.');

        app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $fixture['enrollment']->id,
            amount: '500.00',
            channel: 'cash',
            paymentReference: 'CASH-D5E1D5-NO-OR',
            actor: $fixture['accounting'],
            confirmedAt: CarbonImmutable::now(config('app.timezone'))->subHour(),
        );
    }

    public function test_manual_payment_allocates_one_payment_across_multiple_targets_and_posts_auditable_ledger_rows(): void
    {
        $fixture = $this->activeAssessmentFixture();

        $result = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $fixture['enrollment']->id,
            amount: '1200.00',
            channel: 'cash',
            paymentReference: 'CASH-D5E1D5-SPLIT',
            actor: $fixture['accounting'],
            confirmedAt: CarbonImmutable::now(config('app.timezone'))->subHour(),
            allocations: [
                [
                    'target_type' => AssessmentLine::class,
                    'target_id' => $fixture['lines'][0]->id,
                    'description' => 'Tuition',
                    'amount' => '900.00',
                ],
                [
                    'target_type' => AssessmentLine::class,
                    'target_id' => $fixture['lines'][1]->id,
                    'description' => 'Miscellaneous fees',
                    'amount' => '300.00',
                ],
            ],
            orNumber: 'OR-D5E1D5-SPLIT',
        );

        $payment = Payment::query()->findOrFail($result['payment_id']);
        $allocations = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $allocations);
        $this->assertSame(['900.00', '300.00'], $allocations->pluck('amount')->all());
        $this->assertSame(2, LedgerEntry::query()
            ->where('payment_id', $payment->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->whereNotNull('payment_allocation_id')
            ->where('source_type', PaymentAllocation::class)
            ->count());
        $this->assertSame('1200.00', LedgerEntry::query()
            ->where('payment_id', $payment->id)
            ->sum('amount'));

        $finance = app(FinanceEvidenceService::class)->financeForAssessment(
            $fixture['assessment']->refresh(),
            $fixture['accounting'],
            FinanceEvidenceService::CopyAccounting,
        );
        $this->assertSame(
            ['Tuition', 'Miscellaneous fees'],
            collect($finance['state']['allocation_rows'])->pluck('target')->all(),
        );
        $this->assertCount(
            2,
            app(FinanceEvidenceService::class)
                ->paymentAcknowledgement($payment, $fixture['accounting'], FinanceEvidenceService::CopyAccounting)['summary']['allocation_rows'],
        );
    }

    public function test_payment_allocated_to_prior_term_debt_does_not_clear_the_current_enrollment(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $priorTerm = Term::factory()->create(['label' => 'Second Semester 2025-2026']);
        $priorEnrollment = Enrollment::factory()
            ->for($fixture['profile'])
            ->for($priorTerm)
            ->create();
        $priorBalance = LedgerEntry::query()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $priorTerm->id,
            'enrollment_id' => $priorEnrollment->id,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'prior_balance',
            'amount' => '1500.00',
            'source_type' => Enrollment::class,
            'source_id' => $priorEnrollment->id,
            'description' => 'Prior-term outstanding balance',
            'posted_at' => now()->subMonths(4),
            'state' => 'posted',
        ]);
        try {
            app(PaymentConfirmationService::class)->confirmManualPayment(
                enrollmentId: $fixture['enrollment']->id,
                amount: '1500.00',
                channel: 'bank_transfer',
                paymentReference: 'BANK-D5E1D5-PRIOR-DEBT',
                actor: $fixture['accounting'],
                confirmedAt: CarbonImmutable::now(config('app.timezone'))->subHour(),
                allocations: [[
                    'target_type' => LedgerEntry::class,
                    'target_id' => $priorBalance->id,
                    'amount' => '1500.00',
                ]],
            );
            $this->fail('A current-Term payment must reject a prior-Term allocation target.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payment allocation target is not eligible.', $exception->getMessage());
        }

        $this->assertFalse(Payment::query()->where('provider_reference', 'BANK-D5E1D5-PRIOR-DEBT')->exists());
        $this->assertSame('pending_payment', $fixture['enrollment']->fresh()->status);
    }

    public function test_payment_table_eager_loads_academic_context_without_per_payment_course_queries(): void
    {
        $first = $this->activeAssessmentFixture();
        $second = $this->activeAssessmentFixture();

        foreach ([$first, $second] as $index => $fixture) {
            $this->attachAcademicContext(
                enrollment: $fixture['enrollment'],
                yearLevel: (string) ($index + 1),
                sectionCode: 'D5-TABLE-'.($index + 1),
            );

            app(PaymentConfirmationService::class)->confirmManualPayment(
                enrollmentId: $fixture['enrollment']->id,
                amount: '100.00',
                channel: 'bank_transfer',
                paymentReference: 'BANK-D5E1D5-TABLE-'.($index + 1),
                actor: $first['accounting'],
                confirmedAt: CarbonImmutable::now(config('app.timezone'))->subHour(),
                allocations: [[
                    'target_type' => AssessmentLine::class,
                    'target_id' => $fixture['lines'][0]->id,
                    'amount' => '100.00',
                ]],
            );
        }

        $courseEnrollmentQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$courseEnrollmentQueries): void {
            if (str_contains(strtolower($query->sql), 'from `course_enrollments`')) {
                $courseEnrollmentQueries[] = $query->sql;
            }
        });

        $this->assertFalse(Route::has('filament.admin.resources.payments.index'));
        $this->assertSame(2, Payment::query()->whereIn('provider_reference', [
            'BANK-D5E1D5-TABLE-1',
            'BANK-D5E1D5-TABLE-2',
        ])->count());

        $this->assertCount(
            0,
            $courseEnrollmentQueries,
            'The canonical payment list must not derive legacy academic context per payment.',
        );
    }

    public function test_student_account_presenter_reuses_one_request_scoped_projection(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $firstPresenter = app(StudentAccountPresenter::class);
        $secondPresenter = app(StudentAccountPresenter::class);

        $this->assertSame($firstPresenter, $secondPresenter);

        $firstPresenter->present($fixture['assessment'], $fixture['accounting']);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $secondPresenter->present($fixture['assessment'], $fixture['accounting']);

        $this->assertSame([], $queries);
    }

    public function test_legacy_assessment_payment_editor_is_retired_in_favor_of_enrollment_clearance(): void
    {
        $fixture = $this->activeAssessmentFixture();

        $this->actingAs($fixture['accounting']);
        $this->assertFalse(AssessmentResource::canAccess());

        $actions = collect(Livewire::actingAs($fixture['accounting'])
            ->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->getRouteKey()])
            ->instance()
            ->getCachedHeaderActions())
            ->flatMap(fn ($action): array => $action instanceof ActionGroup
                ? $action->getFlatActions()
                : [$action->getName() => $action]);

        $this->assertArrayHasKey('verifyPaymentEvidence', $actions->all());
    }

    public function test_current_due_follows_exact_obligation_satisfaction_before_the_remaining_balance(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $obligations = $fixture['assessment']->obligations()->orderBy('id')->get();
        $firstEvidence = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['assessment']->term_account_id,
            'claimed_amount' => '600.00',
            'channel' => 'bank_transfer',
            'paid_at' => now()->subMinute(),
            'submitted_by' => $fixture['profile']->user_id,
        ]);

        app(ReviewPaymentEvidence::class)->verify(
            $firstEvidence,
            $fixture['accounting'],
            '600.00',
            'SYN-INDEPENDENT-CHECK-PARTIAL-1',
        );
        $partial = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($fixture['enrollment']);

        $this->assertSame('ActionNeeded', $partial['state']);
        $this->assertSame('600.00', $partial['payment_applied']);
        $this->assertSame('900.00', $partial['balance']);

        $secondEvidence = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['assessment']->term_account_id,
            'supersedes_version_id' => $firstEvidence->id,
            'version' => 2,
            'path' => 'registration-payment-evidence/'.$fixture['assessment']->term_account_id.'/second-synthetic.pdf',
            'checksum' => hash('sha256', 'second-synthetic-payment-evidence-'.$fixture['assessment']->term_account_id),
            'claimed_amount' => '400.00',
            'channel' => 'bank_transfer',
            'paid_at' => now()->subMinute(),
            'submitted_by' => $fixture['profile']->user_id,
        ]);
        app(ReviewPaymentEvidence::class)->verify(
            $secondEvidence,
            $fixture['accounting'],
            '400.00',
            'SYN-INDEPENDENT-CHECK-PARTIAL-2',
        );
        $complete = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($fixture['enrollment']);

        $this->assertSame('ActionNeeded', $complete['state']);
        $this->assertSame('1000.00', $complete['payment_applied']);
        $this->assertSame('500.00', $complete['balance']);
    }

    public function test_partial_payment_with_a_mapped_or_does_not_claim_or_mapping_is_pending(): void
    {
        $fixture = $this->activeAssessmentFixture();

        app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $fixture['enrollment']->id,
            amount: '500.00',
            channel: 'cash',
            paymentReference: 'CASH-D5E1D5-MAPPED-OR',
            actor: $fixture['accounting'],
            confirmedAt: CarbonImmutable::now(config('app.timezone'))->subHour(),
            orNumber: 'OR-D5E1D5-MAPPED',
        );

        $finance = app(FinanceEvidenceService::class)->financeForAssessment(
            $fixture['assessment']->fresh(),
            $fixture['accounting'],
            FinanceEvidenceService::CopyAccounting,
        );

        $this->assertSame('Mapped OR OR-D5E1D5-MAPPED', $finance['state']['payment_evidence']['or_mapping_state']);
        $this->assertSame(
            'Use Pay exact current due for the remaining amount.',
            $finance['state']['payment_evidence']['required_action'],
        );
    }

    public function test_four_step_staff_summary_uses_two_columns_at_large_container_widths(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $this->actingAs($fixture['accounting']);
        $widget = Livewire::test(StaffRoleWorkspaceOverviewWidget::class);
        $method = new ReflectionMethod(StaffRoleWorkspaceOverviewWidget::class, 'getColumns');

        $this->assertSame(
            ['@xl' => 2, '!@lg' => 2],
            $method->invoke($widget->instance()),
        );
    }

    public function test_statement_tables_include_mobile_row_labels(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $student = $fixture['profile']->user;

        $this->actingAs($student)
            ->get(route('finance.statement', ['assessment' => $fixture['assessment']]))
            ->assertOk()
            ->assertSee('finance-responsive-table', false)
            ->assertSee('data-label="Obligation"', false)
            ->assertSee('data-label="Balance"', false);
    }

    public function test_automatic_provider_allocation_does_not_retarget_a_satisfied_obligation(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $obligations = $fixture['assessment']->obligations()->orderBy('id')->get();
        $evidence = PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $fixture['assessment']->term_account_id,
            'claimed_amount' => '1000.00',
            'channel' => 'bank_transfer',
            'paid_at' => now()->subMinute(),
            'submitted_by' => $fixture['profile']->user_id,
        ]);
        app(ReviewPaymentEvidence::class)->verify(
            $evidence,
            $fixture['accounting'],
            '1000.00',
            'SYN-INDEPENDENT-CHECK-PROVIDER-SEAM',
        );

        $attempt = $this->exactDueAttempt($fixture, 'TALA-D5E1D5-SECOND-OBLIGATION');
        $posted = app(PayMongoPaymentPostingService::class)->post(
            attempt: $attempt,
            amount: '500.00',
            providerReference: 'paymongo:D5E1D5-SECOND-OBLIGATION',
            actor: null,
            timestamp: CarbonImmutable::now(config('app.timezone'))->subMinute(),
            description: 'Verified PayMongo payment',
        );
        $allocation = PaymentAllocation::query()
            ->where('payment_id', $posted['payment']->id)
            ->sole();

        $this->assertSame($obligations[1]->id, $allocation->assessment_obligation_id);
        $this->assertNull($allocation->prior_balance_ledger_entry_id);
    }

    public function test_payment_above_the_eligible_outstanding_total_is_rejected_without_partial_writes(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $allocationCountBefore = PaymentAllocation::query()->count();

        try {
            app(PaymentConfirmationService::class)->confirmManualPayment(
                enrollmentId: $fixture['enrollment']->id,
                amount: '1500.01',
                channel: 'cash',
                paymentReference: 'CASH-D5E1D5-OVERPAY',
                actor: $fixture['accounting'],
                confirmedAt: CarbonImmutable::now(config('app.timezone'))->subHour(),
                orNumber: 'OR-D5E1D5-OVERPAY',
            );

            $this->fail('An overpayment must not create finance records.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payment amount cannot exceed the eligible outstanding balance.', $exception->getMessage());
        }

        $this->assertSame(0, Payment::query()->where('provider_reference', 'CASH-D5E1D5-OVERPAY')->count());
        $this->assertSame($allocationCountBefore, PaymentAllocation::query()->count());
        $this->assertSame(0, LedgerEntry::query()
            ->where('enrollment_id', $fixture['enrollment']->id)
            ->where('direction', LedgerEntry::DirectionPayment)
            ->count());
    }

    public function test_allocation_cannot_target_another_students_account_item(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $other = $this->activeAssessmentFixture();

        try {
            app(PaymentConfirmationService::class)->confirmManualPayment(
                enrollmentId: $fixture['enrollment']->id,
                amount: '500.00',
                channel: 'cash',
                paymentReference: 'CASH-D5E1D5-CROSS-STUDENT',
                actor: $fixture['accounting'],
                confirmedAt: CarbonImmutable::now(config('app.timezone'))->subHour(),
                allocations: [[
                    'target_type' => AssessmentLine::class,
                    'target_id' => $other['lines'][0]->id,
                    'amount' => '500.00',
                ]],
                orNumber: 'OR-D5E1D5-CROSS-STUDENT',
            );

            $this->fail('A payment allocation must stay inside the selected student account.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payment allocation target is not eligible.', $exception->getMessage());
        }

        $this->assertFalse(Payment::query()
            ->where('provider_reference', 'CASH-D5E1D5-CROSS-STUDENT')
            ->exists());
    }

    public function test_paymongo_uses_the_same_allocation_seam_and_duplicate_processing_does_not_repost(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $attempt = $this->exactDueAttempt($fixture, 'TALA-D5E1D5-PAYMONGO');
        $timestamp = CarbonImmutable::now(config('app.timezone'))->subMinute();
        $service = app(PayMongoPaymentPostingService::class);

        $first = $service->post(
            attempt: $attempt,
            amount: '1500.00',
            providerReference: 'paymongo:D5E1D5-PAID',
            actor: null,
            timestamp: $timestamp,
            description: 'Verified PayMongo payment',
        );
        $second = $service->post(
            attempt: $attempt->refresh(),
            amount: '1500.00',
            providerReference: 'paymongo:D5E1D5-PAID',
            actor: null,
            timestamp: $timestamp,
            description: 'Verified PayMongo payment',
        );

        $this->assertSame('posted', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, Payment::query()->where('payment_attempt_id', $attempt->id)->count());
        $this->assertSame('1500.00', PaymentAllocation::query()
            ->where('payment_id', $first['payment']->id)
            ->sum('amount'));
        $this->assertSame(2, LedgerEntry::query()
            ->where('payment_id', $first['payment']->id)
            ->whereNotNull('payment_allocation_id')
            ->count());
    }

    /**
     * @return array{
     *     accounting:User,
     *     profile:StudentProfile,
     *     term:Term,
     *     enrollment:Enrollment,
     *     account:TermAccount,
     *     assessment:Assessment,
     *     lines:list<AssessmentLine>
     * }
     */
    private function activeAssessmentFixture(): array
    {
        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);
        $student = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->for($student)->for($program)->create();
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()
            ->for($profile)
            ->for($term)
            ->create(['status' => 'pending_payment']);
        $account = TermAccount::query()->create([
            'enrollment_id' => $enrollment->id,
            'credential_user_id' => $student->id,
            'term_id' => $term->id,
            'state' => TermAccount::StateOpen,
        ]);
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'version' => 1,
            'content_hash' => hash('sha256', 'tal96d5e1d5-assessment-'.$enrollment->id),
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '1500.00',
            'discount_total' => '0.00',
            'total' => '1500.00',
            'required_downpayment' => '1500.00',
            'activated_at' => now()->subDay(),
        ]);

        $lines = collect([
            ['key' => 'tuition', 'description' => 'Tuition', 'amount' => '1000.00'],
            ['key' => 'miscellaneous', 'description' => 'Miscellaneous fees', 'amount' => '500.00'],
        ])->map(function (array $line) use ($assessment, $profile, $program, $term, $enrollment): AssessmentLine {
            $feeRule = FeeRule::query()->create([
                'code' => 'D5E1D5-'.str($line['key'])->upper(),
                'name' => $line['description'],
                'ledger_category' => FeeRule::LedgerCategoryCharge,
                'display_category' => $line['key'] === 'tuition'
                    ? FeeRule::DisplayCategoryTuition
                    : FeeRule::DisplayCategoryMiscellaneous,
                'program_id' => $program->id,
                'term_id' => $term->id,
                'calculation_type' => FeeRule::CalculationFixed,
                'amount' => $line['amount'],
                'effective_from' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'authority' => 'TAL-96D5E1D5 test',
            ]);
            $assessmentLine = AssessmentLine::query()->create([
                'assessment_id' => $assessment->id,
                'fee_rule_id' => $feeRule->id,
                'source_line_key' => $line['key'],
                'description_snapshot' => $line['description'],
                'quantity' => '1.0000',
                'rate' => $line['amount'],
                'amount' => $line['amount'],
                'line_type' => 'fee',
            ]);

            LedgerEntry::query()->create([
                'student_profile_id' => $profile->id,
                'term_id' => $term->id,
                'enrollment_id' => $enrollment->id,
                'direction' => LedgerEntry::DirectionCharge,
                'category' => 'assessment',
                'amount' => $line['amount'],
                'source_type' => AssessmentLine::class,
                'source_id' => $assessmentLine->id,
                'description' => $line['description'],
                'posted_at' => now()->subHours(2),
                'state' => 'posted',
            ]);

            return $assessmentLine;
        })->values()->all();

        foreach ($lines as $index => $line) {
            AssessmentObligation::query()->create([
                'assessment_id' => $assessment->id,
                'sequence' => $index + 1,
                'code' => (string) $line->source_line_key,
                'label' => (string) $line->description_snapshot,
                'purpose' => 'Enrollment',
                'amount' => (string) $line->amount,
                'due_at' => now()->subMinute(),
                'required_for_enrollment' => true,
            ]);
        }

        return compact('accounting', 'profile', 'term', 'enrollment', 'account', 'assessment', 'lines');
    }

    /**
     * @param  array{profile:StudentProfile,account:TermAccount,assessment:Assessment}  $fixture
     */
    private function exactDueAttempt(array $fixture, string $reference): PaymentAttempt
    {
        $snapshot = app(ExactDuePaymentSnapshotService::class)->forAccount($fixture['account']);
        $attempt = PaymentAttempt::query()->create([
            'assessment_id' => $fixture['assessment']->id,
            'term_account_id' => $fixture['account']->id,
            'student_profile_id' => $fixture['profile']->id,
            'assessment_version' => $fixture['assessment']->version,
            'snapshot_created_at' => $snapshot['created_at'],
            'snapshot_checksum' => $snapshot['checksum'],
            'channel' => 'paymongo',
            'provider' => 'paymongo',
            'internal_reference' => $reference,
            'amount' => $snapshot['amount'],
            'currency' => 'PHP',
            'status' => PaymentAttempt::StatusPending,
            'expires_at' => now()->addHour(),
        ]);
        foreach ($snapshot['obligations'] as $target) {
            $attempt->obligations()->create([
                'assessment_obligation_id' => $target['id'],
                'sequence' => $target['sequence'],
                'amount' => $target['amount'],
            ]);
        }

        return $attempt;
    }

    private function attachAcademicContext(
        Enrollment $enrollment,
        string $yearLevel,
        string $sectionCode,
    ): void {
        $profile = $enrollment->studentProfile;
        $entry = CurriculumEntry::factory()
            ->for($profile->curriculumVersion)
            ->create([
                'year_level' => $yearLevel,
                'term_label' => $enrollment->term->label,
                'term_type' => $enrollment->term->type,
            ]);
        $offering = TermOffering::factory()
            ->for($enrollment->term)
            ->for($entry, 'curriculumEntry')
            ->create(['state' => TermOffering::StateScheduled]);
        $section = Section::factory()
            ->for($offering, 'termOffering')
            ->create([
                'code' => $sectionCode,
                'state' => Section::StateOpen,
            ]);

        CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'is_current' => true,
            'proposed_section_id' => $section->id,
            'proposed_at' => now()->subDay(),
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
    }
}
