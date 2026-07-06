<?php

namespace Tests\Unit;

use App\Actions\Finance\PaymentStatusResolver;
use App\Models\Assessment;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentScheduleRow;
use App\Support\DecimalMoney;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class PaymentStatusResolverTest extends TestCase
{
    private PaymentStatusResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new PaymentStatusResolver(new DecimalMoney);
    }

    public function test_returns_no_active_assessment_when_assessment_is_missing(): void
    {
        $status = $this->resolver->resolve(null, '0.00', '0.00', collect(), collect(), collect());

        $this->assertSame(PaymentStatusResolver::StatusNoAssessment, $status);
    }

    public function test_full_paid_when_balance_is_not_positive(): void
    {
        $status = $this->resolver->resolve(new Assessment, '0.00', '2000.00', collect(), collect(), collect());

        $this->assertSame(PaymentStatusResolver::StatusFullPaid, $status);
    }

    public function test_payment_under_review_from_attempt_takes_priority_over_pending(): void
    {
        $attempts = $this->attempts([
            ['id' => 1, 'status' => 'pending'],
            ['id' => 2, 'status' => 'under_review'],
        ]);

        $status = $this->resolver->resolve(new Assessment, '9000.00', '0.00', $attempts, collect(), collect());

        $this->assertSame(PaymentStatusResolver::StatusPaymentUnderReview, $status);
    }

    public function test_payment_under_review_from_payment_evidence(): void
    {
        $payments = collect([new Payment(['evidence_status' => 'under_review'])]);

        $status = $this->resolver->resolve(new Assessment, '9000.00', '0.00', collect(), $payments, collect());

        $this->assertSame(PaymentStatusResolver::StatusPaymentUnderReview, $status);
    }

    public function test_payment_pending_when_only_a_pending_attempt_exists(): void
    {
        $attempts = $this->attempts([['id' => 1, 'status' => 'pending']]);

        $status = $this->resolver->resolve(new Assessment, '9000.00', '0.00', $attempts, collect(), collect());

        $this->assertSame(PaymentStatusResolver::StatusPaymentPending, $status);
    }

    public function test_payment_rejected_when_latest_attempt_failed_and_nothing_posted(): void
    {
        $attempts = $this->attempts([
            ['id' => 1, 'status' => 'pending'],
            ['id' => 5, 'status' => 'failed'],
        ]);

        $status = $this->resolver->resolve(new Assessment, '9000.00', '0.00', $attempts, collect(), collect());

        $this->assertSame(PaymentStatusResolver::StatusPaymentRejected, $status);
    }

    public function test_unpaid_when_nothing_posted_and_no_relevant_attempts(): void
    {
        $status = $this->resolver->resolve(new Assessment, '9000.00', '0.00', collect(), collect(), collect());

        $this->assertSame(PaymentStatusResolver::StatusUnpaid, $status);
    }

    public function test_installment_when_partially_posted_with_a_due_schedule_row(): void
    {
        $schedule = collect([
            new PaymentScheduleRow(['state' => PaymentScheduleRow::StateDue]),
        ]);

        $status = $this->resolver->resolve(new Assessment, '7000.00', '2000.00', collect(), collect(), $schedule);

        $this->assertSame(PaymentStatusResolver::StatusInstallment, $status);
    }

    public function test_partially_paid_when_posted_without_a_due_schedule_row(): void
    {
        $schedule = collect([
            new PaymentScheduleRow(['state' => 'paid']),
        ]);

        $status = $this->resolver->resolve(new Assessment, '7000.00', '2000.00', collect(), collect(), $schedule);

        $this->assertSame(PaymentStatusResolver::StatusPartiallyPaid, $status);
    }

    public function test_failed_attempt_does_not_override_posted_progress(): void
    {
        $attempts = $this->attempts([['id' => 3, 'status' => 'failed']]);
        $schedule = collect([
            new PaymentScheduleRow(['state' => PaymentScheduleRow::StateDue]),
        ]);

        $status = $this->resolver->resolve(new Assessment, '7000.00', '2000.00', $attempts, collect(), $schedule);

        $this->assertSame(PaymentStatusResolver::StatusInstallment, $status);
    }

    /**
     * @param  list<array{id:int,status:string}>  $rows
     * @return Collection<int, PaymentAttempt>
     */
    private function attempts(array $rows): Collection
    {
        return collect($rows)->map(function (array $row): PaymentAttempt {
            $attempt = new PaymentAttempt(['status' => $row['status']]);
            $attempt->id = $row['id'];

            return $attempt;
        });
    }
}
