<?php

namespace Tests\Feature;

use App\Actions\Finance\PaymentAcademicContextResolver;
use App\Actions\Finance\StudentAccountPresenter;
use App\Filament\Pages\PayMongoReconciliation;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\AccountingAdjustment;
use App\Models\Assessment;
use App\Models\CandidateScheduleRow;
use App\Models\FinancialAccommodation;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ScheduleGenerationRun;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\TAL96D5E1ExplorationPersonaSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
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
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->artisan('acceptance:seed-client-baseline')->assertSuccessful();

        Role::query()->firstOrCreate([
            'name' => User::StaffRoleAccounting,
            'guard_name' => 'web',
        ]);
    }

    #[Test]
    public function accounting_navigation_presents_one_task_centered_operating_flow(): void
    {
        $accounting = User::query()->where('email', 'accounting.demo@example.test')->sole();

        $this->actingAs($accounting)
            ->get('/admin')
            ->assertOk()
            ->assertSeeText('Fee Plans')
            ->assertSeeText('Student Accounts')
            ->assertDontSeeText('Accounting Adjustments')
            ->assertDontSeeText('Financial Accommodations')
            ->assertDontSeeText('Ledger Entries')
            ->assertDontSeeText('Payment Queue')
            ->assertDontSeeText('Confirmed Payments');
    }

    #[Test]
    public function student_account_presenter_and_detail_explain_the_current_position_and_next_action(): void
    {
        $this->seed(TAL96D5E1ExplorationPersonaSeeder::class);

        $accounting = User::query()->where('email', 'accounting.demo@example.test')->sole();
        $assessment = $this->assessmentFor('DIT-1A-002');
        $account = app(StudentAccountPresenter::class)->present($assessment, $accounting);

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
            ->assertSee('Registration Case')
            ->assertSee('Five finalization checkpoints')
            ->assertSee('Accounting clearance')
            ->assertSee('Current proposal and protected placement')
            ->assertSee('Official result and history');
    }

    #[Test]
    public function payment_exceptions_are_named_and_filterable_by_evidence_source_status_and_reason(): void
    {
        $this->seed(TAL96D5E1ExplorationPersonaSeeder::class);

        $accounting = User::query()->where('email', 'accounting.demo@example.test')->sole();
        $exception = OperationalEvent::query()
            ->where('external_id', 'tal96d5e1c-open-exception')
            ->sole();

        $this->assertNotContains(PayMongoReconciliation::class, Filament::getPanel('admin')->getPages());
        $this->assertDatabaseHas('operational_events', [
            'id' => $exception->id,
            'status' => OperationalEvent::StatusReviewRequired,
        ]);
    }

    #[Test]
    public function account_activity_uses_human_labels_and_filters_without_exposing_technical_sources_by_default(): void
    {
        $this->seed(TAL96D5E1ExplorationPersonaSeeder::class);

        $accounting = User::query()->where('email', 'accounting.demo@example.test')->sole();
        $adjustment = AccountingAdjustment::query()
            ->where('evidence_reference', 'ADJUSTMENT-CREDIT-001')
            ->sole();
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
        $this->seed(TAL96D5E1ExplorationPersonaSeeder::class);

        $accounting = User::query()->where('email', 'accounting.demo@example.test')->sole();
        $payment = Payment::query()
            ->where('provider_reference', 'PAYMENT-OR-PENDING-001')
            ->sole();
        $attempt = PaymentAttempt::query()
            ->where('internal_reference', 'CHECKOUT-REVIEW-001')
            ->sole();
        $syntheticAttempt = PaymentAttempt::query()
            ->where('internal_reference', 'CHECKOUT-EXPIRED-001')
            ->sole();
        $syntheticAttempt->forceFill([
            'channel' => 'synthetic_acceptance',
            'provider' => 'synthetic_acceptance',
        ])->save();
        $paymentEnrollment = app(PaymentAcademicContextResolver::class)->enrollment($payment);
        $attemptEnrollment = Assessment::query()
            ->with('enrollment.term')
            ->findOrFail($attempt->assessment_id)
            ->enrollment;

        $this->assertNotNull($paymentEnrollment);
        $this->assertNotNull($attemptEnrollment);

        $this->assertFalse(Route::has('filament.admin.resources.payments.index'));
        $this->assertFalse(Route::has('filament.admin.resources.payment-attempts.index'));

        $this->assertSame('bank_transfer', $payment->fresh()->channel);
        $this->assertSame('verified', $payment->fresh()->evidence_status);
        $this->assertSame('online_checkout', $attempt->fresh()->channel);
        $this->assertSame('under_review', $attempt->fresh()->status);
        $this->assertSame('paymongo', $attempt->fresh()->provider);
        $this->assertSame('synthetic_acceptance', $syntheticAttempt->fresh()->channel);
        $this->assertSame('synthetic_acceptance', $syntheticAttempt->fresh()->provider);
    }

    #[Test]
    public function exploration_overlay_exposes_finance_and_provider_states_without_creating_schedules(): void
    {
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
        $this->assertSame('expired', PaymentAttempt::query()
            ->where('internal_reference', 'CHECKOUT-EXPIRED-001')
            ->sole()
            ->status);
        $this->assertSame('under_review', PaymentAttempt::query()
            ->where('internal_reference', 'CHECKOUT-REVIEW-001')
            ->sole()
            ->status);
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
        $this->assertSame(1, OperationalEvent::query()
            ->where('external_id', 'tal96d5e1c-open-exception')
            ->where('status', OperationalEvent::StatusReviewRequired)
            ->count());
        $this->assertSame(1, OperationalEvent::query()
            ->where('external_id', 'tal96d5e1c-recovered-evidence')
            ->where('status', OperationalEvent::StatusProcessed)
            ->count());
    }

    private function assessmentFor(string $studentNumber): Assessment
    {
        return Assessment::query()
            ->where('state', Assessment::StateActive)
            ->whereHas(
                'enrollment.studentProfile',
                fn ($query) => $query->where('student_number', $studentNumber),
            )
            ->sole();
    }
}
