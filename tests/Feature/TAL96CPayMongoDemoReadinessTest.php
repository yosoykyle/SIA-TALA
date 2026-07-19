<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceEvidenceService;
use App\Models\Assessment;
use App\Models\AssessmentLine;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\FeeRule;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Models\StudentProfile;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TAL96CPayMongoDemoReadinessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        config()->set('tala_integrations.payments.driver', 'paymongo');
        config()->set('tala_integrations.payments.paymongo.base_url', 'https://api.paymongo.com');
        config()->set('tala_integrations.payments.paymongo.livemode', false);
        config()->set('tala_integrations.payments.paymongo.public_key', 'pk_test_tal96c');
        config()->set('tala_integrations.payments.paymongo.secret_key', 'sk_test_tal96c');
        config()->set('tala_integrations.payments.paymongo.webhook_signature', 'whsk_tal96c');
    }

    public function test_command_creates_client_baseline_and_exact_unpaid_paymongo_demo_fixture(): void
    {
        $exitCode = Artisan::call('acceptance:prepare-paymongo-demo');
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('outcome=created', $output);
        $this->assertStringContainsString('student=student.demo@example.test', $output);
        $this->assertStringContainsString('amount_due=2000.00', $output);
        $this->assertStringContainsString('readiness=PASS', $output);
        $this->assertStringNotContainsString('pk_test_tal96c', $output);
        $this->assertStringNotContainsString('sk_test_tal96c', $output);
        $this->assertStringNotContainsString('whsk_tal96c', $output);

        $this->assertSame(47, StudentProfile::query()->count());
        $this->assertSame(54, TermOffering::query()->count());
        $this->assertSame(4, FeeRule::query()->count());

        $student = User::query()->where('email', 'student.demo@example.test')->sole();
        $this->assertTrue($student->hasVerifiedEmail());
        $profile = StudentProfile::query()->whereBelongsTo($student)->sole();
        $enrollment = Enrollment::query()->whereBelongsTo($profile)->sole();
        $courseEnrollment = CourseEnrollment::query()->whereBelongsTo($enrollment)->sole();
        $chargeRule = FeeRule::query()->where('code', 'TAL96C-DEMO-CHARGE')->sole();
        $assessment = Assessment::query()->whereBelongsTo($enrollment)->sole();
        $line = AssessmentLine::query()->whereBelongsTo($assessment)->sole();
        $scheduleRow = PaymentScheduleRow::query()->whereBelongsTo($assessment)->sole();
        $chargeLedger = LedgerEntry::query()
            ->where('source_type', AssessmentLine::class)
            ->where('source_id', $line->id)
            ->where('direction', LedgerEntry::DirectionCharge)
            ->sole();

        $this->assertSame('pending_payment', $enrollment->status);
        $this->assertSame('regular', $enrollment->student_type);
        $this->assertSame(CourseEnrollment::StatusActive, $courseEnrollment->status);
        $this->assertTrue($courseEnrollment->termOffering?->curriculumEntry?->curriculumVersion()->is($profile->curriculumVersion));
        $this->assertSame('2000.00', $chargeRule->amount);
        $this->assertSame(FeeRule::CalculationFixed, $chargeRule->calculation_type);
        $this->assertSame(FeeRule::DisplayCategoryTuition, $chargeRule->display_category);
        $this->assertSame(Assessment::StateActive, $assessment->state);
        $this->assertSame('2000.00', $assessment->subtotal);
        $this->assertSame('2000.00', $assessment->total);
        $this->assertSame('2000.00', $assessment->required_downpayment);
        $this->assertTrue($line->feeRule()->is($chargeRule));
        $this->assertSame('2000.00', $line->amount);
        $this->assertSame(PaymentScheduleRow::StateDue, $scheduleRow->state);
        $this->assertSame('2000.00', $scheduleRow->amount);
        $this->assertSame('2000.00', $chargeLedger->amount);

        $finance = app(FinanceEvidenceService::class)->studentFinance($student);
        $this->assertTrue($finance['available']);
        $this->assertSame('2000.00', $finance['current_due_amount']);
        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, DB::table('webhook_calls')->count());
    }

    public function test_exact_unpaid_fixture_rerun_is_a_no_op(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:prepare-paymongo-demo'));
        $before = $this->fixtureCounts();
        $latestAssessmentUpdate = Assessment::query()->max('updated_at');

        $exitCode = Artisan::call('acceptance:prepare-paymongo-demo');
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, $output);
        $this->assertStringContainsString('outcome=already_present', $output);
        $this->assertSame($before, $this->fixtureCounts());
        $this->assertSame($latestAssessmentUpdate, Assessment::query()->max('updated_at'));
    }

    public function test_mutated_demo_fixture_fails_closed_without_repairing_or_adding_records(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('acceptance:prepare-paymongo-demo'));
        $chargeRule = FeeRule::query()->where('code', 'TAL96C-DEMO-CHARGE')->sole();
        $chargeRule->update(['amount' => '2100.00']);
        $before = $this->fixtureCounts();

        $exitCode = Artisan::call('acceptance:prepare-paymongo-demo');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('partial, changed, paid, or conflicting', $output);
        $this->assertSame('2100.00', $chargeRule->fresh()?->amount);
        $this->assertSame($before, $this->fixtureCounts());
    }

    public function test_paymongo_sandbox_guard_rejects_mock_driver_before_writing(): void
    {
        config()->set('tala_integrations.payments.driver', 'mock');

        $exitCode = Artisan::call('acceptance:prepare-paymongo-demo');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('PayMongo payment driver is required', $output);
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertSame(0, Assessment::query()->count());
    }

    /** @return array<string, int> */
    private function fixtureCounts(): array
    {
        return [
            'users' => User::query()->count(),
            'students' => StudentProfile::query()->count(),
            'offerings' => TermOffering::query()->count(),
            'fee_rules' => FeeRule::query()->count(),
            'enrollments' => Enrollment::query()->count(),
            'course_enrollments' => CourseEnrollment::query()->count(),
            'assessments' => Assessment::query()->count(),
            'assessment_lines' => AssessmentLine::query()->count(),
            'schedule_rows' => PaymentScheduleRow::query()->count(),
            'ledger_entries' => LedgerEntry::query()->count(),
            'payment_attempts' => PaymentAttempt::query()->count(),
            'payments' => Payment::query()->count(),
            'webhook_calls' => DB::table('webhook_calls')->count(),
        ];
    }
}
