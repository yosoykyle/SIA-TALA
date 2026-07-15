<?php

namespace App\Console\Commands;

use App\Actions\Finance\FinanceEvidenceService;
use App\Actions\Integrations\Payments\CreatePaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentCheckoutException;
use App\Actions\Integrations\Payments\PayMongoPaymentGateway;
use App\Models\Assessment;
use App\Models\User;
use App\Support\DecimalMoney;
use Illuminate\Console\Command;

class CreatePayMongoSandboxCheckout extends Command
{
    protected $signature = 'integrations:paymongo-sandbox-checkout
        {--assessment-id= : Existing active assessments.id owned by the sandbox student}
        {--success-url= : PayMongo success redirect URL}
        {--cancel-url= : PayMongo cancel redirect URL}
        {--description=TALA sandbox checkout : Checkout description shown to PayMongo}';

    protected $description = 'Create a PayMongo test checkout for an existing active student assessment.';

    public function handle(
        DecimalMoney $money,
        FinanceEvidenceService $financeEvidence,
        PayMongoPaymentGateway $gateway,
    ): int {
        if ((bool) config('tala_integrations.payments.paymongo.livemode')) {
            $this->error('Refusing to create a sandbox checkout while PAYMONGO_LIVEMODE=true.');

            return self::FAILURE;
        }

        $assessmentId = trim((string) $this->option('assessment-id'));

        if ($assessmentId === '') {
            $this->error('An existing active assessment is required. Pass --assessment-id=<id>.');

            return self::FAILURE;
        }

        $assessment = Assessment::query()
            ->with('enrollment.studentProfile.user')
            ->find((int) $assessmentId);
        $actor = $assessment?->enrollment?->studentProfile?->user;

        if (! $assessment instanceof Assessment || $assessment->state !== Assessment::StateActive || ! $actor instanceof User) {
            $this->error('The selected assessment must be active and belong to an existing student account.');

            return self::FAILURE;
        }

        $checkoutCreator = new CreatePaymentCheckoutSession($gateway, $money, $financeEvidence);

        try {
            $session = $checkoutCreator->create(
                actor: $actor,
                assessmentId: (int) $assessment->id,
                successUrl: $this->redirectUrl('success-url', 'success'),
                cancelUrl: $this->redirectUrl('cancel-url', 'cancelled'),
                description: (string) $this->option('description'),
                metadata: [
                    'module' => 'sandbox',
                    'created_by' => 'integrations:paymongo-sandbox-checkout',
                ],
            );
        } catch (PaymentCheckoutException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('PayMongo sandbox checkout ready.');
        $this->line('outcome='.$session['outcome']);
        $this->line('payment_attempt_id='.$session['payment_attempt_id']);
        $this->line('provider_checkout_session_id='.$session['provider_checkout_session_id']);
        $this->line('amount='.$session['amount']);
        $this->line('checkout_url='.$session['checkout_url']);
        $this->warn('Complete the checkout URL in the browser to make PayMongo send a signed webhook.');

        return self::SUCCESS;
    }

    private function redirectUrl(string $option, string $checkoutState): string
    {
        $configured = trim((string) $this->option($option));

        return $configured !== ''
            ? $configured
            : route('filament.student.pages.finance', ['checkout' => $checkoutState]);
    }
}
