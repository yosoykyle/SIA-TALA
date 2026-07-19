<?php

namespace App\Console\Commands;

use App\Actions\Integrations\Payments\PayMongoSandboxEnvironmentGuard;
use App\Actions\Integrations\Payments\PreparePayMongoDemoFixture;
use App\Actions\SystemAdministration\AcceptanceBaselineEnvironmentGuard;
use Illuminate\Console\Command;
use Throwable;

final class PreparePayMongoDemo extends Command
{
    protected $signature = 'acceptance:prepare-paymongo-demo';

    protected $description = 'Prepare the guarded TAL-96C client-baseline PayMongo demonstration fixture.';

    public function handle(
        AcceptanceBaselineEnvironmentGuard $baselineGuard,
        PayMongoSandboxEnvironmentGuard $payMongoGuard,
        PreparePayMongoDemoFixture $fixture,
    ): int {
        try {
            $baselineGuard->assertSafe();
            $payMongoGuard->assertSafe(['public_key', 'secret_key', 'webhook_signature']);
            $result = $fixture->prepare();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('TAL-96C PayMongo demonstration fixture ready.');
        $this->line('outcome='.$result['outcome']);
        $this->line('student='.$result['student']);
        $this->line('enrollment_id='.$result['enrollment_id']);
        $this->line('assessment_id='.$result['assessment_id']);
        $this->line('course_enrollment_id='.$result['course_enrollment_id']);
        $this->line('amount_due='.$result['amount_due']);
        $this->line('readiness='.$result['readiness']);

        return self::SUCCESS;
    }
}
