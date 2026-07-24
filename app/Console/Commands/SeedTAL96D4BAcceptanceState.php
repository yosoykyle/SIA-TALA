<?php

namespace App\Console\Commands;

use App\Actions\SystemAdministration\AcceptanceBaselineEnvironmentGuard;
use Database\Seeders\TAL96D4BAcceptanceStateSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SeedTAL96D4BAcceptanceState extends Command
{
    protected $signature = 'acceptance:seed-tal96d4b-states';

    protected $description = 'Add the guarded TAL-96D4B grade and lifecycle acceptance overlay to an existing test baseline.';

    public function handle(
        AcceptanceBaselineEnvironmentGuard $environmentGuard,
        TAL96D4BAcceptanceStateSeeder $seeder,
    ): int {
        try {
            $environmentGuard->assertSafe();
            DB::transaction(fn () => $seeder->run(), 3);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('TAL-96D4B grade and lifecycle acceptance states are ready.');
        $this->line('outcome=created_or_refreshed');
        $this->line('database='.DB::connection()->getDatabaseName());
        $this->line('scheduling_baseline=preserved');

        return self::SUCCESS;
    }
}
