<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceEvidenceService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Actions\Finance\StudentAccountPresenter;
use App\Actions\Integrations\Payments\PayMongoPaymentPostingService;
use App\Filament\Resources\Assessments\Pages\ViewAssessment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Widgets\StaffRoleWorkspaceOverviewWidget;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

    public function test_manual_bank_transfer_posts_without_an_or_and_remains_pending_or_reconciliation(): void
    {
        $fixture = $this->activeAssessmentFixture();

        Livewire::actingAs($fixture['accounting'])
            ->test(ViewAssessment::class, ['record' => $fixture['assessment']->getRouteKey()])
            ->callAction('recordManualPayment', data: [
                'amount' => '600.00',
                'channel' => 'bank_transfer',
                'payment_reference' => 'BANK-D5E1D5-001',
                'paid_at' => now()->subHour()->format('Y-m-d H:i:s'),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Manual payment recorded');

        $payment = Payment::query()->where('provider_reference', 'BANK-D5E1D5-001')->sole();

        $this->assertNull($payment->or_number);
        $this->assertSame('bank_transfer', $payment->channel);
        $this->assertSame('600.00', PaymentAllocation::query()
            ->where('payment_id', $payment->id)
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
        $this->attachAcademicContext(
            enrollment: $fixture['enrollment'],
            yearLevel: '3',
            sectionCode: 'D5-CURRENT',
        );

        $result = app(PaymentConfirmationService::class)->confirmManualPayment(
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

        $paymentLedgerEntry = LedgerEntry::query()
            ->where('payment_id', $result['payment_id'])
            ->sole();
        $acknowledgement = app(FinanceEvidenceService::class)->paymentAcknowledgement(
            Payment::query()->findOrFail($result['payment_id']),
            $fixture['accounting'],
            FinanceEvidenceService::CopyAccounting,
        );

        $this->assertFalse($result['finance_cleared']);
        $this->assertSame('0.00', $result['total_confirmed_payments']);
        $this->assertSame('pending_payment', $fixture['enrollment']->fresh()->status);
        $this->assertSame($priorEnrollment->id, $paymentLedgerEntry->enrollment_id);
        $this->assertSame($priorTerm->id, $paymentLedgerEntry->term_id);
        $this->assertSame('Level 3', $acknowledgement['summary']['year_level']);
        $this->assertSame('D5-CURRENT', $acknowledgement['summary']['section']);
        $this->assertSame($fixture['term']->label, $acknowledgement['summary']['term']);
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

        Livewire::actingAs($first['accounting'])
            ->test(ListPayments::class)
            ->assertCanSeeTableRecords(Payment::query()->get());

        $this->assertCount(
            1,
            $courseEnrollmentQueries,
            'The payment list must eager-load academic context once instead of querying it per payment.',
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

    public function test_accounting_can_add_or_remove_rows_in_a_multi_target_allocation_editor(): void
    {
        $fixture = $this->activeAssessmentFixture();

        Livewire::actingAs($fixture['accounting'])
            ->test(ViewAssessment::class, ['record' => $fixture['assessment']->getRouteKey()])
            ->mountAction('recordManualPayment')
            ->assertSchemaComponentExists(
                'allocations',
                checkComponentUsing: fn (Repeater $component): bool => $component->isAddable()
                    && $component->isDeletable(),
            );
    }

    public function test_current_due_follows_the_outstanding_schedule_allocation_before_the_remaining_balance(): void
    {
        $fixture = $this->activeAssessmentFixture();
        PaymentScheduleRow::query()->create([
            'assessment_id' => $fixture['assessment']->id,
            'sequence' => 1,
            'category' => PaymentScheduleRow::CategoryDownpayment,
            'due_date' => now()->subDay()->toDateString(),
            'amount' => '1000.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);

        app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $fixture['enrollment']->id,
            amount: '600.00',
            channel: 'bank_transfer',
            paymentReference: 'BANK-D5E1D5-CURRENT-DUE-PARTIAL',
            actor: $fixture['accounting'],
            confirmedAt: CarbonImmutable::now(config('app.timezone'))->subHours(2),
        );

        $partial = app(FinanceEvidenceService::class)->financeForAssessment(
            $fixture['assessment']->fresh(),
            $fixture['accounting'],
            FinanceEvidenceService::CopyAccounting,
        );

        $this->assertSame('400.00', $partial['current_due_amount']);
        $this->assertSame('PHP 400.00', $partial['state']['current_due']);
        $this->assertSame('Downpayment', $partial['state']['current_due_source']);

        app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $fixture['enrollment']->id,
            amount: '400.00',
            channel: 'bank_transfer',
            paymentReference: 'BANK-D5E1D5-CURRENT-DUE-COMPLETE',
            actor: $fixture['accounting'],
            confirmedAt: CarbonImmutable::now(config('app.timezone'))->subHour(),
        );

        $complete = app(FinanceEvidenceService::class)->financeForAssessment(
            $fixture['assessment']->fresh(),
            $fixture['accounting'],
            FinanceEvidenceService::CopyAccounting,
        );

        $this->assertSame('500.00', $complete['current_due_amount']);
        $this->assertSame('PHP 500.00', $complete['state']['current_due']);
        $this->assertSame('Current Balance', $complete['state']['current_due_source']);
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
            'Use Pay Current Due for the remaining amount.',
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
            ->assertSee('data-label="Charge"', false)
            ->assertSee('data-label="Date Posted"', false);
    }

    public function test_automatic_allocation_does_not_retarget_assessment_lines_already_paid_through_the_schedule(): void
    {
        $fixture = $this->activeAssessmentFixture();
        $scheduleRow = PaymentScheduleRow::query()->create([
            'assessment_id' => $fixture['assessment']->id,
            'sequence' => 1,
            'category' => PaymentScheduleRow::CategoryDownpayment,
            'due_date' => now()->subDay()->toDateString(),
            'amount' => '1500.00',
            'state' => PaymentScheduleRow::StateDue,
        ]);

        $first = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $fixture['enrollment']->id,
            amount: '1500.00',
            channel: 'bank_transfer',
            paymentReference: 'BANK-D5E1D5-SCHEDULE-FULL',
            actor: $fixture['accounting'],
            confirmedAt: CarbonImmutable::now(config('app.timezone'))->subHours(2),
        );

        $this->assertSame($scheduleRow->id, PaymentAllocation::query()
            ->where('payment_id', $first['payment_id'])
            ->sole()
            ->payment_schedule_row_id);

        $penalty = LedgerEntry::query()->create([
            'student_profile_id' => $fixture['profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'direction' => LedgerEntry::DirectionPenalty,
            'category' => 'late_payment_penalty',
            'amount' => '200.00',
            'source_type' => Enrollment::class,
            'source_id' => $fixture['enrollment']->id,
            'description' => 'Late payment penalty',
            'posted_at' => now()->subHour(),
            'state' => 'posted',
        ]);

        $second = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $fixture['enrollment']->id,
            amount: '200.00',
            channel: 'bank_transfer',
            paymentReference: 'BANK-D5E1D5-PENALTY',
            actor: $fixture['accounting'],
            confirmedAt: CarbonImmutable::now(config('app.timezone'))->subMinutes(30),
        );
        $allocation = PaymentAllocation::query()
            ->where('payment_id', $second['payment_id'])
            ->sole();

        $this->assertSame($penalty->id, $allocation->prior_balance_ledger_entry_id);
        $this->assertNull($allocation->assessment_line_id);
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
        $attempt = PaymentAttempt::query()->create([
            'assessment_id' => $fixture['assessment']->id,
            'student_profile_id' => $fixture['profile']->id,
            'channel' => 'gcash',
            'provider' => 'paymongo',
            'internal_reference' => 'TALA-D5E1D5-PAYMONGO',
            'amount' => '600.00',
            'currency' => 'PHP',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);
        $timestamp = CarbonImmutable::now(config('app.timezone'))->subMinute();
        $service = app(PayMongoPaymentPostingService::class);

        $first = $service->post(
            attempt: $attempt,
            amount: '600.00',
            providerReference: 'paymongo:D5E1D5-PAID',
            actor: null,
            timestamp: $timestamp,
            description: 'Verified PayMongo payment',
        );
        $second = $service->post(
            attempt: $attempt->refresh(),
            amount: '600.00',
            providerReference: 'paymongo:D5E1D5-PAID',
            actor: null,
            timestamp: $timestamp,
            description: 'Verified PayMongo payment',
        );

        $this->assertSame('posted', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, Payment::query()->where('payment_attempt_id', $attempt->id)->count());
        $this->assertSame('600.00', PaymentAllocation::query()
            ->where('payment_id', $first['payment']->id)
            ->sum('amount'));
        $this->assertSame(1, LedgerEntry::query()
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
        $assessment = Assessment::query()->create([
            'enrollment_id' => $enrollment->id,
            'version' => 1,
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

        return compact('accounting', 'profile', 'term', 'enrollment', 'assessment', 'lines');
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
            'proposed_section_id' => $section->id,
            'proposed_at' => now()->subDay(),
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);
    }
}
