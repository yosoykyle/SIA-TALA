<?php

namespace Tests\Unit;

use App\Actions\Finance\PaymentStatusResolver;
use App\Models\Assessment;
use App\Models\Payment;
use App\Models\PaymentEvidenceVersion;
use App\Models\PaymentScheduleRow;
use App\Support\DecimalMoney;
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

    public function test_payment_under_review_from_payment_evidence(): void
    {
        $payments = collect([new Payment(['evidence_status' => 'under_review'])]);

        $status = $this->resolver->resolve(new Assessment, '9000.00', '0.00', $payments, collect());

        $this->assertSame(PaymentStatusResolver::StatusPaymentUnderReview, $status);
    }

    public function test_payment_under_review_from_submitted_evidence_version(): void
    {
        $evidenceVersions = collect([
            new PaymentEvidenceVersion(['version' => 1, 'state' => PaymentEvidenceVersion::StateRejected]),
            new PaymentEvidenceVersion(['version' => 2, 'state' => PaymentEvidenceVersion::StateSubmitted]),
        ]);

        $status = $this->resolver->resolve(new Assessment, '9000.00', '0.00', collect(), collect(), $evidenceVersions);

        $this->assertSame(PaymentStatusResolver::StatusPaymentUnderReview, $status);
    }

    public function test_payment_rejected_when_latest_evidence_version_is_rejected_and_nothing_posted(): void
    {
        $evidenceVersions = collect([
            new PaymentEvidenceVersion(['version' => 1, 'state' => PaymentEvidenceVersion::StateRejected]),
        ]);

        $status = $this->resolver->resolve(new Assessment, '9000.00', '0.00', collect(), collect(), $evidenceVersions);

        $this->assertSame(PaymentStatusResolver::StatusPaymentRejected, $status);
    }

    public function test_unpaid_when_nothing_posted_and_no_evidence(): void
    {
        $status = $this->resolver->resolve(new Assessment, '9000.00', '0.00', collect(), collect(), collect());

        $this->assertSame(PaymentStatusResolver::StatusUnpaid, $status);
    }

    public function test_installment_when_partially_posted_with_a_due_schedule_row(): void
    {
        $schedule = collect([
            new PaymentScheduleRow(['state' => PaymentScheduleRow::StateDue]),
        ]);

        $status = $this->resolver->resolve(new Assessment, '7000.00', '2000.00', collect(), $schedule, collect());

        $this->assertSame(PaymentStatusResolver::StatusInstallment, $status);
    }

    public function test_partially_paid_when_posted_without_a_due_schedule_row(): void
    {
        $schedule = collect([
            new PaymentScheduleRow(['state' => 'paid']),
        ]);

        $status = $this->resolver->resolve(new Assessment, '7000.00', '2000.00', collect(), $schedule, collect());

        $this->assertSame(PaymentStatusResolver::StatusPartiallyPaid, $status);
    }

    public function test_rejected_evidence_does_not_override_posted_progress(): void
    {
        $evidenceVersions = collect([
            new PaymentEvidenceVersion(['version' => 1, 'state' => PaymentEvidenceVersion::StateRejected]),
        ]);
        $schedule = collect([
            new PaymentScheduleRow(['state' => PaymentScheduleRow::StateDue]),
        ]);

        $status = $this->resolver->resolve(new Assessment, '7000.00', '2000.00', collect(), $schedule, $evidenceVersions);

        $this->assertSame(PaymentStatusResolver::StatusInstallment, $status);
    }
}
