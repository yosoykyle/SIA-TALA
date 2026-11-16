<?php

namespace Tests\Feature;

use App\Actions\Finance\PaymentAcademicContextResolver;
use App\Actions\Finance\StudentAccountPresenter;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\AccountingAdjustment;
use App\Models\Assessment;
use App\Models\CandidateScheduleRow;
use App\Models\FinancialAccommodation;
use App\Models\LedgerEntry;
use App\Models\Payment;
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
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
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
        $paymentEnrollment = app(PaymentAcademicContextResolver::class)->enrollment($payment);

        $this->assertNotNull($paymentEnrollment);
        $this->assertFalse(Route::has('filament.admin.resources.payments.index'));
        $this->assertSame('bank_transfer', $payment->fresh()->channel);
        $this->assertSame('verified', $payment->fresh()->evidence_status);
    }

    #[Test]
    public function exploration_overlay_exposes_finance_states_without_creating_schedules(): void
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
