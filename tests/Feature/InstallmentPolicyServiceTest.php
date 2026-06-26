<?php

namespace Tests\Feature;

use App\Actions\Finance\InstallmentPolicyService;
use Tests\TestCase;

class InstallmentPolicyServiceTest extends TestCase
{
    public function test_installment_policy_evaluation_uses_configurable_milestones_and_end_of_month_due_rule(): void
    {
        $source = $this->source(InstallmentPolicyService::class);

        $this->assertStringContainsString('installment_policies', $source);
        $this->assertStringContainsString('installment_policy_milestones', $source);
        $this->assertStringContainsString('month_offset', $source);
        $this->assertStringContainsString('required_percentage', $source);
        $this->assertStringContainsString('end_of_month', $source);
        $this->assertStringContainsString('endOfMonth()', $source);
    }

    public function test_installment_policy_uses_three_day_grace_and_recurring_monthly_penalty_cycles(): void
    {
        $source = $this->source(InstallmentPolicyService::class);

        $this->assertStringContainsString('addDays((int) $policy->grace_days)', $source);
        $this->assertStringContainsString('overdueCycleKeys', $source);
        $this->assertStringContainsString('penalty_frequency', $source);
        $this->assertStringContainsString('penalty_rate', $source);
        $this->assertStringContainsString('Late Penalty - ', $source);
        $this->assertStringContainsString('penaltyAlreadyPosted', $source);
    }

    public function test_active_promissory_is_reported_but_does_not_count_as_finance_payment(): void
    {
        $source = $this->source(InstallmentPolicyService::class);

        $this->assertStringContainsString('hasActivePromissory', $source);
        $this->assertStringNotContainsString('&& ! $hasActivePromissory', $source);
        $this->assertStringContainsString("whereIn('status', ['approved', 'active'])", $source);
    }

    private function source(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        return $source;
    }
}
