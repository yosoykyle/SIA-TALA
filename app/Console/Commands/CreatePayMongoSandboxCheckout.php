<?php

namespace App\Console\Commands;

use App\Actions\Integrations\Payments\CreatePaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentCheckoutRequest;
use App\Actions\Integrations\Payments\PayMongoPaymentGateway;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CreatePayMongoSandboxCheckout extends Command
{
    protected $signature = 'integrations:paymongo-sandbox-checkout
        {--amount=2000.00 : Test checkout amount in PHP}
        {--student-profile-id= : Existing student_profiles.id to attach the payment attempt to}
        {--success-url= : PayMongo success redirect URL}
        {--cancel-url= : PayMongo cancel redirect URL}
        {--description=TALA sandbox checkout : Checkout description shown to PayMongo}';

    protected $description = 'Create a PayMongo test checkout session and pending local payment attempt.';

    public function handle(DecimalMoney $money, PayMongoPaymentGateway $gateway): int
    {
        if ((bool) config('paymongo.livemode')) {
            $this->error('Refusing to create a sandbox checkout while PAYMONGO_LIVEMODE=true.');

            return self::FAILURE;
        }

        $amount = $money->normalize((string) $this->option('amount'));
        $studentProfileId = $this->studentProfileId($amount);
        $checkoutCreator = new CreatePaymentCheckoutSession($gateway, $money);

        $session = $checkoutCreator->create(new PaymentCheckoutRequest(
            studentProfileId: $studentProfileId,
            amount: $amount,
            description: (string) $this->option('description'),
            channel: 'paymongo',
            successUrl: $this->redirectUrl('success-url', '/payments/success'),
            cancelUrl: $this->redirectUrl('cancel-url', '/payments/cancel'),
            metadata: [
                'module' => 'sandbox',
                'created_by' => 'integrations:paymongo-sandbox-checkout',
            ],
        ));

        $this->info('PayMongo sandbox checkout created.');
        $this->line('payment_attempt_id='.$session['payment_attempt_id']);
        $this->line('provider_checkout_session_id='.$session['provider_checkout_session_id']);
        $this->line('amount='.$session['amount']);
        $this->line('checkout_url='.$session['checkout_url']);
        $this->warn('Complete the checkout URL in the browser to make PayMongo send a signed webhook.');

        return self::SUCCESS;
    }

    private function studentProfileId(string $amount): int
    {
        $option = $this->option('student-profile-id');

        if ($option !== null && trim((string) $option) !== '') {
            return (int) $option;
        }

        $now = CarbonImmutable::now(config('app.timezone'))->toDateTimeString();
        $email = 'paymongo-sandbox@tala.test';

        $userId = DB::table('users')->where('email', $email)->value('id');

        if ($userId === null) {
            $sandboxUser = [
                'name' => 'PayMongo Sandbox Student',
                'username' => 'paymongo-sandbox',
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('users', 'first_name')) {
                $sandboxUser = [
                    ...$sandboxUser,
                    'first_name' => 'PayMongo',
                    'middle_name' => null,
                    'last_name' => 'Sandbox Student',
                    'suffix' => null,
                ];
            }

            $userId = DB::table('users')->insertGetId($sandboxUser);
        }

        $studentProfileId = DB::table('student_profiles')->where('user_id', $userId)->value('id');

        if ($studentProfileId !== null) {
            DB::table('student_profiles')->where('id', $studentProfileId)->update([
                'current_balance' => $amount,
                'updated_at' => $now,
            ]);

            return (int) $studentProfileId;
        }

        return (int) DB::table('student_profiles')->insertGetId([
            'user_id' => $userId,
            'student_id' => 'TALA-SANDBOX-0001',
            'year_level' => '1st Year',
            'operational_status' => 'Active',
            'current_balance' => $amount,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function redirectUrl(string $option, string $path): string
    {
        $configured = $this->option($option);

        if ($configured !== null && trim((string) $configured) !== '') {
            return (string) $configured;
        }

        return rtrim((string) config('app.url'), '/').$path;
    }
}
