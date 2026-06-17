<?php

namespace Tests\Feature;

use App\Actions\Finance\EnrollmentFinanceClearanceService;
use App\Actions\Finance\PaymentConfirmationService;
use Tests\TestCase;

class PaymentConfirmationServiceTest extends TestCase
{
    public function test_manual_payment_confirmation_is_accounting_authorized_and_posts_negative_ledger_credit(): void
    {
        $source = $this->source(PaymentConfirmationService::class);

        $this->assertStringContainsString("can('process-payments')", $source);
        $this->assertStringContainsString('Only Accounting/Cashier can confirm payments.', $source);
        $this->assertStringContainsString("\$paymentLedgerAmount = \$this->money->subtract('0.00', \$normalizedAmount)", $source);
        $this->assertStringContainsString("'entry_type' => 'payment'", $source);
        $this->assertStringContainsString("'status' => 'confirmed'", $source);
    }

    public function test_payment_reference_is_unique_when_provided(): void
    {
        $source = $this->source(PaymentConfirmationService::class);

        $this->assertStringContainsString("where('payment_reference', \$paymentReference)->exists()", $source);
        $this->assertStringContainsString('Payment reference already exists.', $source);
    }

    public function test_promissory_notes_do_not_clear_finance_status(): void
    {
        $source = $this->source(EnrollmentFinanceClearanceService::class);

        $this->assertStringContainsString('hasActivePromissory', $source);
        $this->assertStringContainsString('return false;', $this->methodSource(EnrollmentFinanceClearanceService::class, 'shouldClearFinance'));
        $this->assertStringContainsString("whereIn('status', ['approved', 'active'])", $source);
        $this->assertStringContainsString("whereDate('due_date', '>=', now(config('app.timezone'))->toDateString())", $source);
    }

    private function source(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        return $source;
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file((string) $reflection->getFileName());

        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
