<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FeeRule;
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

    }

    public function test_historical_fee_rule_demo_command_is_retired_without_writing(): void
    {
        $exitCode = Artisan::call('acceptance:prepare-paymongo-demo');

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('historical FeeRule-based PayMongo demo fixture is retired', Artisan::output());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertSame(0, Assessment::query()->count());
        $this->assertSame(0, FeeRule::query()->count());
    }

    public function test_retired_command_rerun_is_a_no_op(): void
    {
        $this->assertSame(Command::FAILURE, Artisan::call('acceptance:prepare-paymongo-demo'));
        $this->assertSame(Command::FAILURE, Artisan::call('acceptance:prepare-paymongo-demo'));
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertSame(0, Assessment::query()->count());
    }

    public function test_retired_command_never_repairs_or_changes_existing_records(): void
    {
        $user = User::factory()->create();
        $before = $user->getAttributes();

        $this->assertSame(Command::FAILURE, Artisan::call('acceptance:prepare-paymongo-demo'));
        $after = array_intersect_key($user->fresh()?->getAttributes() ?? [], $before);
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
        $this->assertSame(0, Enrollment::query()->count());
        $this->assertSame(0, Assessment::query()->count());
    }

    public function test_retired_command_does_not_require_or_inspect_provider_credentials(): void
    {
        config()->set('tala_integrations.payments.driver', 'mock');
        config()->set('tala_integrations.payments.paymongo.secret_key', null);

        $this->assertSame(Command::FAILURE, Artisan::call('acceptance:prepare-paymongo-demo'));
        $this->assertStringContainsString('provider activation belongs to Slice 6', Artisan::output());
        $this->assertSame(0, User::query()->count());
    }
}
