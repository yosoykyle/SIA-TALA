<?php

namespace App\Console\Commands;

use App\Actions\SystemAdministration\AcceptanceBaselineEnvironmentGuard;
use App\Actions\SystemAdministration\TAL96D5E1ExplorationPersonaCatalog;
use Database\Seeders\TAL96D5E1ExplorationPersonaSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies or inspects the guarded TAL-96D5E1 first-time exploration overlay.
 *
 * This command has no application UI, never invokes CP-SAT, and is restricted
 * to APP_ENV=testing with MySQL test_tala_db by the shared environment guard.
 */
final class SeedTAL96D5E1Exploration extends Command
{
    protected $signature = 'acceptance:seed-tal96d5e1-exploration
        {--check : Inspect the exploration catalogue without writing}';

    protected $description = 'Prepare deterministic defense personas on the verified client-aligned MIN fixture.';

    public function handle(
        AcceptanceBaselineEnvironmentGuard $environmentGuard,
        TAL96D5E1ExplorationPersonaSeeder $seeder,
        TAL96D5E1ExplorationPersonaCatalog $catalog,
    ): int {
        try {
            $environmentGuard->assertSafe();

            if (! $this->option('check')) {
                DB::transaction(fn () => $seeder->run(), attempts: 3);
            }

            $report = $catalog->report();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('outcome='.($this->option('check') ? 'inspection_only' : 'created_or_refreshed'));
        $this->line('database='.DB::connection()->getDatabaseName());
        $this->line('coverage_state='.$report['coverage_state']);
        $this->line('personas='.$report['personas']);
        $this->line('denied_login_personas='.$report['denied_login_personas']);
        $this->line('student_profiles='.$report['student_profiles']);
        $this->line('current_students='.$report['current_students']);
        $this->line('historical_case_profiles='.$report['historical_case_profiles']);
        $this->line('cohorts='.$report['cohorts']);
        $this->line('term_offerings='.$report['term_offerings']);
        $this->line('scheduling_demands='.$report['scheduling_demands']);
        $this->line('faculty='.$report['faculty']);
        $this->line('staff_ready='.($report['staff_ready'] ? 'yes' : 'no'));
        $this->line('applicants_ready='.($report['applicants_ready'] ? 'yes' : 'no'));
        $this->line('students_ready='.($report['students_ready'] ? 'yes' : 'no'));
        $this->line('presentation_fixture_ready='.($report['presentation_fixture_ready'] ? 'yes' : 'no'));
        $this->line('scheduling_outputs_empty='.($report['scheduling_outputs_empty'] ? 'yes' : 'no'));
        $this->line('solver_invoked=no');
        $this->line('external_provider_called=no');

        if ($report['coverage_state'] !== 'PASS') {
            $this->error('The TAL-96D5E1 exploration catalogue is incomplete.');

            return self::FAILURE;
        }

        $this->info('The client-aligned presentation personas are ready.');

        return self::SUCCESS;
    }
}
