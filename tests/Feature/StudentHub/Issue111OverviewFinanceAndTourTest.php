<?php

namespace Tests\Feature\StudentHub;

use App\Actions\Finance\SchoolPaymentChannels;
use App\Filament\Student\Pages\Dashboard as StudentDashboard;
use App\Filament\Student\Pages\Finance as StudentFinance;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\AssessmentObligation;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Issue111OverviewFinanceAndTourTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', User::StaffRoleAccounting] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function student_overview_hides_embedded_schedule_and_lists_only_unpaid_payables_with_due_dates_and_settled_state(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 09:00:00', 'Asia/Manila'));

        $fixture = $this->studentAccountFixture([
            'student_number' => 'SIA-2026-8111',
            'obligations' => [
                [
                    'sequence' => 1,
                    'code' => 'DOWNPAYMENT',
                    'label' => 'Enrollment downpayment',
                    'amount' => '10000.00',
                    'due_at' => CarbonImmutable::parse('2026-08-20 17:00:00', 'Asia/Manila')->utc(),
                    'paid' => true,
                ],
                [
                    'sequence' => 2,
                    'code' => 'MIDTERM',
                    'label' => 'Midterm installment',
                    'amount' => '12000.00',
                    'due_at' => CarbonImmutable::parse('2026-10-15 17:00:00', 'Asia/Manila')->utc(),
                    'paid' => false,
                ],
                [
                    'sequence' => 3,
                    'code' => 'FINALS',
                    'label' => 'Final installment',
                    'amount' => '8000.00',
                    'due_at' => null,
                    'paid' => false,
                ],
            ],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($fixture['student'])
            ->test(StudentDashboard::class)
            ->assertDontSee('student-home-schedule')
            ->assertDontSee('Open full schedule')
            ->assertDontSee('Enrollment downpayment')
            ->assertDontSee('Satisfied')
            ->assertSee('Midterm installment')
            ->assertSee('Due Oct 15, 2026')
            ->assertSee('PHP 12,000.00')
            ->assertSee('Final installment')
            ->assertSee('Ask Accounting for the due date')
            ->assertSee('PHP 8,000.00');

        // Mark remaining obligations paid and verify concise settled state on Overview.
        foreach ([$fixture['obligations'][1], $fixture['obligations'][2]] as $idx => $obligation) {
            $payment = Payment::factory()->for($fixture['profile'])->for($fixture['term'])->create([
                'term_account_id' => $fixture['account']->id,
                'amount' => $obligation->amount,
                'state' => Payment::StatePosted,
                'evidence_status' => 'verified',
                'channel' => 'cash',
                'paid_at' => now()->subMinutes(10 - $idx),
                'verified_at' => now()->subMinutes(5 - $idx),
            ]);
            PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'assessment_obligation_id' => $obligation->id,
                'sequence' => 1,
                'amount' => $obligation->amount,
            ]);
        }

        Livewire::actingAs($fixture['student'])
            ->test(StudentDashboard::class)
            ->assertSee('No remaining balance')
            ->assertSee('All current term fees are settled.')
            ->assertDontSee('Midterm installment')
            ->assertDontSee('Satisfied');
    }

    #[Test]
    public function student_finance_removes_redundant_fee_breakdown_and_displays_student_number_on_statement(): void
    {
        $accounting = User::factory()->create(['status' => User::StatusActive]);
        $accounting->assignRole(User::StaffRoleAccounting);

        app(SchoolPaymentChannels::class)->update([
            'bank_transfer' => [
                'enabled' => true,
                'accounts' => [[
                    'id' => 'bank-111',
                    'bank_name' => 'Land Bank of the Philippines',
                    'account_name' => 'Servitech Institute Asia',
                    'account_number' => '0011-2233-44',
                ]],
            ],
            'e_wallets' => ['enabled' => false, 'accounts' => []],
            'cash' => ['enabled' => true, 'location' => 'Finance Window 2'],
        ], $accounting, 'Enable bank transfer and cashier for Issue #111 test');

        $fixture = $this->studentAccountFixture([
            'student_number' => 'SIA-2026-8112',
            'obligations' => [
                [
                    'sequence' => 1,
                    'code' => 'ENROLLMENT',
                    'label' => 'Enrollment fee',
                    'amount' => '15000.00',
                    'due_at' => now()->subDay(),
                    'paid' => false,
                ],
            ],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($fixture['student'])
            ->test(StudentFinance::class)
            ->assertSee('Pay Online')
            ->assertSee('Print / Export Statement')
            ->assertSee('This week:')
            ->assertSee('Finance Window 2')
            ->assertDontSee("What you're paying for")
            ->assertDontSee('Cashier monthly operating calendar');

        $this->actingAs($fixture['student'])
            ->get(route('finance.statement', $fixture['assessment']))
            ->assertOk()
            ->assertSee('Statement of Account')
            ->assertSee('SIA-2026-8112')
            ->assertSee($fixture['profile']->program->code);
    }

    #[Test]
    public function student_hub_renders_prominent_student_number_in_topbar_and_non_blocking_quick_tour_markup(): void
    {
        $fixture = $this->studentAccountFixture([
            'student_number' => 'SIA-2026-8113',
            'obligations' => [],
        ]);

        foreach (['/student', '/student/finance'] as $path) {
            $this->actingAs($fixture['student'])
                ->get($path)
                ->assertOk()
                ->assertSeeHtml('data-tala-student-number')
                ->assertSee('SIA-2026-8113')
                ->assertSeeHtml('id="tala-student-tour-invite"')
                ->assertSeeHtml('data-tour-user-id="'.$fixture['student']->id.'"')
                ->assertSeeHtml('data-tour-role="student"')
                ->assertSeeHtml('data-tour-version="1"')
                ->assertSee('Take a quick tour')
                ->assertSee('Quick tour');
        }
    }

    /**
     * @param  array{
     *     student_number: string,
     *     obligations: list<array{sequence:int,code:string,label:string,amount:string,due_at:?CarbonImmutable,paid:bool}>
     * }  $config
     * @return array{student:User,profile:StudentProfile,term:Term,enrollment:Enrollment,account:TermAccount,assessment:Assessment,obligations:list<AssessmentObligation>}
     */
    private function studentAccountFixture(array $config): array
    {
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');

        $program = Program::factory()->create(['code' => 'BSIT-111']);
        $profile = StudentProfile::factory()->for($student)->for($program)->create([
            'student_number' => $config['student_number'],
        ]);
        $term = Term::factory()->create(['label' => 'First Semester 2026-2027']);
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'credential_user_id' => $student->id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'enrolled',
            'registered_at' => now()->subDays(5),
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $enrollment->id,
            'credential_user_id' => $student->id,
            'term_id' => $term->id,
        ]);

        $total = collect($config['obligations'])->sum(fn (array $item): float => (float) $item['amount']);
        $totalFormatted = number_format($total, 2, '.', '');

        $assessment = Assessment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'state' => Assessment::StateActive,
            'subtotal' => $totalFormatted,
            'total' => $totalFormatted,
        ]);

        AssessmentLine::query()->create([
            'assessment_id' => $assessment->id,
            'source_line_key' => 'TUITION-1',
            'description_snapshot' => 'Tuition fee',
            'quantity' => '1.0000',
            'rate' => $totalFormatted,
            'amount' => $totalFormatted,
            'line_type' => 'charge',
        ]);

        $obligations = [];
        foreach ($config['obligations'] as $item) {
            $obligation = AssessmentObligation::factory()->create([
                'assessment_id' => $assessment->id,
                'sequence' => $item['sequence'],
                'code' => $item['code'],
                'label' => $item['label'],
                'purpose' => $item['sequence'] === 1 ? 'Enrollment' : 'TermPayment',
                'amount' => $item['amount'],
                'due_at' => $item['due_at'],
                'required_for_enrollment' => $item['sequence'] === 1,
            ]);
            $obligations[] = $obligation;

            if ($item['paid']) {
                $payment = Payment::factory()->for($profile)->for($term)->create([
                    'term_account_id' => $account->id,
                    'amount' => $item['amount'],
                    'state' => Payment::StatePosted,
                    'evidence_status' => 'verified',
                    'channel' => 'cash',
                    'paid_at' => now()->subDays(2),
                    'verified_at' => now()->subDays(2),
                ]);
                PaymentAllocation::query()->create([
                    'payment_id' => $payment->id,
                    'assessment_obligation_id' => $obligation->id,
                    'sequence' => 1,
                    'amount' => $item['amount'],
                ]);
            }
        }

        return [
            'student' => $student,
            'profile' => $profile,
            'term' => $term,
            'enrollment' => $enrollment,
            'account' => $account,
            'assessment' => $assessment,
            'obligations' => $obligations,
        ];
    }
}
