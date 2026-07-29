<?php

namespace Tests\Feature;

use App\Actions\Finance\StudentAccountPresenter;
use App\Filament\Pages\PayMongoReconciliation;
use App\Filament\Resources\Assessments\Pages\ViewAssessment;
use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Resources\LedgerEntries\Pages\ViewLedgerEntry;
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
            ->assertSeeText('Fee Setup')
            ->assertSeeText('Student Accounts')
            ->assertSeeText('Payment Exceptions')
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
            ->test(ViewAssessment::class, ['record' => $assessment->getRouteKey()])
            ->assertSee('Student Account')
            ->assertSee('Current Position')
            ->assertSee('Finance Gate')
            ->assertSee('Responsible Office')
            ->assertSee('Next Action')
            ->assertSee('Account Activity')
            ->assertSee('Payment Attempts')
            ->assertSee('Payments and Official Receipts')
            ->assertSee('Adjustments and Reversals')
            ->assertSee('Financial Accommodation');
    }

    #[Test]
    public function payment_exceptions_are_named_and_filterable_by_evidence_source_status_and_reason(): void
    {
        $this->seed(TAL96D5E1ExplorationPersonaSeeder::class);

        $accounting = User::query()->where('email', 'accounting.demo@example.test')->sole();
        $exception = OperationalEvent::query()
            ->where('external_id', 'tal96d5e1c-open-exception')
            ->sole();

        $this->actingAs($accounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(PayMongoReconciliation::class)
            ->assertSee('Payment Exceptions')
            ->assertSee('Signed webhook')
            ->assertSee('Unknown Reference')
            ->assertCanSeeTableRecords([$exception])
            ->filterTable('channel', OperationalEvent::ChannelWebhook)
            ->assertCanSeeTableRecords([$exception])
            ->filterTable('status', OperationalEvent::StatusReviewRequired)
            ->assertCanSeeTableRecords([$exception])
            ->filterTable('reason', 'unknown_reference')
            ->assertCanSeeTableRecords([$exception]);
    }

    #[Test]
    public function account_activity_uses_human_labels_and_filters_without_exposing_technical_sources_by_default(): void
    {
        $this->seed(TAL96D5E1ExplorationPersonaSeeder::class);

        $accounting = User::query()->where('email', 'accounting.demo@example.test')->sole();
        $adjustment = AccountingAdjustment::query()
            ->where('evidence_reference', 'TAL96D5E1C-CREDIT')
            ->sole();
        $activity = LedgerEntry::query()->findOrFail($adjustment->ledger_entry_id);

        $this->actingAs($accounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListLedgerEntries::class)
            ->assertCanSeeTableRecords([$activity])
            ->assertSee('Balance Effect')
            ->assertSee('Source')
            ->assertSee('Payment Method')
            ->assertSee('Posted By')
            ->assertDontSeeText(AccountingAdjustment::class)
            ->filterTable('direction', LedgerEntry::DirectionAdjustment)
            ->assertCanSeeTableRecords([$activity])
            ->filterTable('category', AccountingAdjustment::LedgerCategoryAdjustment)
            ->assertCanSeeTableRecords([$activity])
            ->filterTable('state', 'posted')
            ->assertCanSeeTableRecords([$activity])
            ->filterTable('source_type', AccountingAdjustment::class)
            ->assertCanSeeTableRecords([$activity]);

        Livewire::test(ViewLedgerEntry::class, ['record' => $activity->getRouteKey()])
            ->assertSee('Account Activity')
            ->assertSee('Balance Effect')
            ->assertSee('Accounting adjustment')
            ->assertSee('Posted By')
            ->assertSee('Technical Trace');
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
        $this->assertSame(270, StudentProfile::query()->count());
        $this->assertSame('expired', PaymentAttempt::query()
            ->where('internal_reference', 'TAL96D5E1C-SYNTHETIC-EXPIRED')
            ->sole()
            ->status);
        $this->assertSame('under_review', PaymentAttempt::query()
            ->where('internal_reference', 'TAL96D5E1C-SYNTHETIC-UNDER-REVIEW')
            ->sole()
            ->status);
        $this->assertNull(Payment::query()
            ->where('provider_reference', 'TAL96D5E1C-PENDING-OR')
            ->sole()
            ->or_number);
        $this->assertSame(2, AccountingAdjustment::query()
            ->whereIn('evidence_reference', [
                'TAL96D5E1C-CREDIT',
                'TAL96D5E1C-REVERSAL',
            ])
            ->count());
        $this->assertEqualsCanonicalizing(
            [FinancialAccommodation::StatusActive, FinancialAccommodation::StatusExpired],
            FinancialAccommodation::query()
                ->whereIn('certification_reference', [
                    'TAL96D5E1C-ACTIVE',
                    'TAL96D5E1C-EXPIRED',
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
