<?php

namespace App\Console\Commands;

use App\Actions\SystemAdministration\AcceptanceBaselineEnvironmentGuard;
use App\Actions\SystemAdministration\TAL96D5BStateCoverageMatrix;
use Database\Seeders\TAL96D5BAcceptanceStateSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies the test-only TAL-96D5B operational-state overlay.
 *
 * This command has no application UI, does not run CP-SAT, and is guarded to
 * APP_ENV=testing with MySQL test_tala_db by the shared environment guard.
 */
final class SeedTAL96D5BAcceptanceState extends Command
{
    protected $signature = 'acceptance:seed-tal96d5b-states';

    protected $description = 'Add deterministic operational cases to the verified client-aligned MIN fixture.';

    public function handle(
        AcceptanceBaselineEnvironmentGuard $environmentGuard,
        TAL96D5BAcceptanceStateSeeder $seeder,
        TAL96D5BStateCoverageMatrix $coverageMatrix,
    ): int {
        try {
            $environmentGuard->assertSafe();
            DB::transaction(fn () => $seeder->run(), 3);
            $report = $coverageMatrix->report();

            if ($report['coverage_state'] !== 'PASS') {
                throw new \RuntimeException('The TAL-96D5B operational-state coverage matrix is incomplete.');
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $humanGates = collect($report['state_families'])
            ->where('disposition', 'human_gate')
            ->count();

        $this->info('The client-aligned operational presentation cases are ready.');
        $this->line('outcome=created_or_refreshed');
        $this->line('database='.DB::connection()->getDatabaseName());
        $this->line('coverage_state='.$report['coverage_state']);
        $this->line('covered_roles='.count($report['roles']));
        $this->line('covered_state_families='.count($report['state_families']));
        $this->line('human_gate_families='.$humanGates);
        $this->line('scheduling_baseline=preserved');
        $this->line('solver_invoked=no');

        return self::SUCCESS;
    }
}
