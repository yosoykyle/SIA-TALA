<?php

namespace Tests\Feature;

use App\Actions\Finance\AccountingAdjustmentService;
use App\Actions\Finance\PaymentAcademicContextResolver;
use App\Actions\Finance\PaymentConfirmationService;
use App\Actions\Finance\StudentAccountPresenter;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\AccountingAdjustment;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\AssessmentObligation;
use App\Models\CandidateScheduleRow;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\FinancialAccommodation;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Program;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\TAL96D5E1ExplorationPersonaSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

#[Group('acceptance-fixture')]
final class TAL96D5E1CAccountingRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate(User::StaffRoleAccounting, 'web');
    }

    #[Test]
    public function accounting_navigation_presents_one_task_centered_operating_flow(): void
    {
        $accounting = $this->createAccountingUser();
        $accounting->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $accounting->saveAppAuthenticationRecoveryCodes(['stored-code']);

        $this->followingRedirects()->actingAs($accounting)
            ->get('/admin')
            ->assertOk()
            ->assertSeeText('Fee plans')
            ->assertSeeText('Student accounts')
            ->assertDontSeeText('Accounting Adjustments')
            ->assertDontSeeText('Financial Accommodations')
            ->assertDontSeeText('Ledger Entries')
            ->assertDontSeeText('Payment Queue')
            ->assertDontSeeText('Confirmed Payments');
    }

    #[Test]
    public function student_account_presenter_and_detail_explain_the_current_position_and_next_action(): void
    {
        $fixture = $this->createMinimalAccountFixture(studentNumber: 'DIT-1A-002');
        $accounting = $fixture['accounting'];
        $assessment = $fixture['assessment'];

        app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $assessment->enrollment_id,
            amount: '1000.00',
            channel: 'cash',
            paymentReference: 'PAYMENT-PARTIAL-MIN-001',
            actor: $accounting,
            confirmedAt: CarbonImmutable::now(config('app.timezone'))->subDays(5),
            orNumber: 'OR-PAYMENT-PARTIAL-MIN-001',
        );

        $account = app(StudentAccountPresenter::class)->present($assessment->fresh(), $accounting);

        $this->assertSame('DIT-1A-002', $account['student_number']);
        $this->assertSame('Accounting', $account['responsible_office']);
        $this->assertNotSame('', $account['next_action']);
        $this->assertContains($account['finance_gate_status'], ['Cleared', 'Blocked']);
        $this->assertNotSame('', $account['finance_gate_source']);
        $this->assertNotSame('', $account['payment_status']);
        $this->assertNotSame('', $account['current_due']);
        $this->assertNotSame('', $account['remaining_balance']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($accounting)
            ->test(ViewEnrollment::class, ['record' => $assessment->enrollment->getRouteKey()])
            ->assertSee('Due now')
            ->assertSee('Enrollment payment pending');

        Livewire::actingAs($accounting)->withQueryParams(['view' => 'account'])
            ->test(ViewEnrollment::class, ['record' => $assessment->enrollment->getRouteKey()])
            ->assertSee('Approved charges')
            ->assertSee('Payment dates')
            ->assertSee('Remaining term fees')
            ->assertSee('Payments and coverage')
            ->assertSee('Account history')
            ->assertSee('Open account statement');
    }

    #[Test]
    public function account_activity_uses_human_labels_and_filters_without_exposing_technical_sources_by_default(): void
    {
        $fixture = $this->createMinimalAccountFixture();
        $posted = app(AccountingAdjustmentService::class)->post([
            'student_profile_id' => $fixture['student_profile']->id,
            'term_id' => $fixture['term']->id,
            'enrollment_id' => $fixture['enrollment']->id,
            'source_ledger_entry_id' => $fixture['charge']->id,
            'adjustment_type' => AccountingAdjustment::TypeStudentAccountCredit,
            'amount' => '25.00',
            'reason' => 'Approved credit applied after Accounting reviewed the supporting record.',
            'evidence_reference' => 'ADJUSTMENT-CREDIT-001',
        ], $fixture['accounting'], CarbonImmutable::now(config('app.timezone'))->subMinute());

        $adjustment = AccountingAdjustment::query()->findOrFail($posted['adjustment_id']);
        $activity = LedgerEntry::query()->findOrFail($adjustment->ledger_entry_id);

        $this->assertFalse(Route::has('filament.admin.resources.ledger-entries.index'));
        $this->assertFalse(Route::has('filament.admin.resources.ledger-entries.view'));
        $this->assertDatabaseHas('ledger_entries', [
            'id' => $activity->id,
            'direction' => LedgerEntry::DirectionAdjustment,
        ]);
    }

    #[Test]
    public function accounting_payment_tables_format_internal_codes_without_changing_raw_state_or_filters(): void
    {
        $fixture = $this->createMinimalAccountFixture();
        $result = app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: $fixture['enrollment']->id,
            amount: '125.00',
            channel: 'bank_transfer',
            paymentReference: 'PAYMENT-OR-PENDING-001',
            actor: $fixture['accounting'],
            confirmedAt: CarbonImmutable::now(config('app.timezone'))->subMinute(),
        );

        $payment = Payment::query()->findOrFail($result['payment_id']);
        $paymentEnrollment = app(PaymentAcademicContextResolver::class)->enrollment($payment);

        $this->assertNotNull($paymentEnrollment);
        $this->assertFalse(Route::has('filament.admin.resources.payments.index'));
        $this->assertSame('bank_transfer', $payment->fresh()->channel);
        $this->assertSame('verified', $payment->fresh()->evidence_status);
    }

    #[Test]
    public function exploration_overlay_exposes_finance_states_without_creating_schedules(): void
    {
        $this->artisan('acceptance:seed-client-baseline')->assertSuccessful();

        $before = [
            'runs' => ScheduleGenerationRun::query()->count(),
            'candidates' => CandidateScheduleRow::query()->count(),
            'meetings' => SectionMeeting::query()->count(),
            'jobs' => DB::table('jobs')->count(),
        ];

        $this->seed(TAL96D5E1ExplorationPersonaSeeder::class);
        $this->seed(TAL96D5E1ExplorationPersonaSeeder::class);

        $this->assertSame($before, [
            'runs' => ScheduleGenerationRun::query()->count(),
            'candidates' => CandidateScheduleRow::query()->count(),
            'meetings' => SectionMeeting::query()->count(),
            'jobs' => DB::table('jobs')->count(),
        ]);
        $this->assertSame(49, StudentProfile::query()->count());
        $this->assertNull(Payment::query()
            ->where('provider_reference', 'PAYMENT-OR-PENDING-001')
            ->sole()
            ->or_number);
        $this->assertSame(2, AccountingAdjustment::query()
            ->whereIn('evidence_reference', [
                'ADJUSTMENT-CREDIT-001',
                'ADJUSTMENT-REVERSAL-001',
            ])
            ->count());
        $this->assertEqualsCanonicalizing(
            [FinancialAccommodation::StatusActive, FinancialAccommodation::StatusExpired],
            FinancialAccommodation::query()
                ->whereIn('certification_reference', [
                    'ACCOMMODATION-ACTIVE-001',
                    'ACCOMMODATION-EXPIRED-001',
                ])
                ->pluck('status')
                ->all(),
        );
    }

    private function createAccountingUser(): User
    {
        $accounting = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $accounting->assignRole(Role::findOrCreate(User::StaffRoleAccounting, 'web'));

        return $accounting;
    }

    /**
     * @return array{
     *     accounting: User,
     *     student_profile: StudentProfile,
     *     term: Term,
     *     enrollment: Enrollment,
     *     assessment: Assessment,
     *     charge: LedgerEntry
     * }
     */
    private function createMinimalAccountFixture(string $studentNumber = 'DIT-1A-002'): array
    {
        $accounting = $this->createAccountingUser();
        $studentUser = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $studentUser->assignRole(Role::findOrCreate('student', 'web'));

        $program = Program::factory()->create();
        $curriculum = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'state' => CurriculumVersion::StateActive,
        ]);
        $studentProfile = StudentProfile::factory()->create([
            'user_id' => $studentUser->id,
            'student_number' => $studentNumber,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculum->id,
        ]);

        $effectiveAt = CarbonImmutable::now(config('app.timezone'))->subDays(10)->startOfDay();
        $term = Term::factory()->create([
            'starts_on' => $effectiveAt->toDateString(),
            'ends_on' => $effectiveAt->addMonths(4)->toDateString(),
            'state' => Term::StateActive,
        ]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $studentProfile->id,
            'credential_user_id' => $studentUser->id,
            'term_id' => $term->id,
            'status' => 'pending_payment',
            'student_type' => 'regular',
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'registered_at' => $effectiveAt,
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $enrollment->id,
            'credential_user_id' => $studentUser->id,
            'term_id' => $term->id,
            'state' => TermAccount::StateOpen,
        ]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'version' => 1,
            'state' => Assessment::StateActive,
            'currency' => 'PHP',
            'subtotal' => '3000.00',
            'discount_total' => '0.00',
            'total' => '3000.00',
            'required_downpayment' => '2000.00',
            'activated_by' => $accounting->id,
            'activated_at' => $effectiveAt,
        ]);
        $line = $assessment->lines()->create([
            'source_line_key' => 'SYNTH-FIXED-CHARGE',
            'description_snapshot' => 'Synthetic fixed presentation charge',
            'quantity' => 1,
            'rate' => '3000.00',
            'amount' => '3000.00',
            'line_type' => 'Fixed',
        ]);
        $charge = LedgerEntry::query()->create([
            'source_type' => AssessmentLine::class,
            'source_id' => $line->id,
            'direction' => LedgerEntry::DirectionCharge,
            'student_profile_id' => $studentProfile->id,
            'term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'category' => FeeRule::LedgerCategoryCharge,
            'amount' => '3000.00',
            'description' => $line->description_snapshot,
            'posted_by' => $accounting->id,
            'posted_at' => $effectiveAt,
            'state' => 'posted',
        ]);

        AssessmentObligation::factory()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'code' => 'ENROLLMENT-DUE',
            'label' => 'Enrollment amount currently due',
            'purpose' => 'Enrollment clearance',
            'amount' => '2000.00',
            'due_at' => $effectiveAt,
            'required_for_enrollment' => true,
        ]);
        AssessmentObligation::factory()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 2,
            'code' => 'REMAINING-BALANCE',
            'label' => 'Remaining term balance',
            'purpose' => 'Term account balance',
            'amount' => '1000.00',
            'due_at' => CarbonImmutable::now(config('app.timezone'))->addYear()->startOfDay(),
            'required_for_enrollment' => false,
        ]);

        return [
            'accounting' => $accounting,
            'student_profile' => $studentProfile,
            'term' => $term,
            'enrollment' => $enrollment,
            'assessment' => $assessment,
            'charge' => $charge,
        ];
    }
}
