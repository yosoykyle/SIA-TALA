<?php

namespace App\Console\Commands;

use App\Actions\SystemAdministration\AcceptanceBaselineEnvironmentGuard;
use Database\Seeders\ClientAlignedAcceptanceBaselineSeeder;
use Database\Seeders\SchedulingAcceptanceScenarioSeeder;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Provides the TAL-96D2C operator command for guarded scheduling acceptance fixtures.
 *
 * This command has no application UI. Its environment guard restricts execution
 * to testing on test_tala_db, and its fixture workflow never invokes the solver.
 */
final class SeedSchedulingAcceptanceScenario extends Command
{
    protected $signature = 'acceptance:seed-scheduling-scenario
        {scenario : MIN, MIDDLE, or MAX}
        {--check : Inspect the selected scenario without writing}';

    protected $description = 'Inspect or seed a guarded scheduling acceptance scenario and workload manifest.';

    public function handle(
        AcceptanceBaselineEnvironmentGuard $environmentGuard,
        SchedulingAcceptanceScenarioSeeder $seeder,
    ): int {
        try {
            $environmentGuard->assertSafe();
            $seeder->forScenario((string) $this->argument('scenario'));
            $manifest = $seeder->manifest();
            $state = $seeder->state();

            if ($this->option('check')) {
                $readiness = $state === ClientAlignedAcceptanceBaselineSeeder::StateComplete
                    && $seeder->readinessPasses()
                        ? 'PASS'
                        : 'NOT_READY';
                $this->writeManifest('inspection_only', $state, $readiness, $seeder);

                return $readiness === 'PASS' ? self::SUCCESS : self::FAILURE;
            }

            if ($state === ClientAlignedAcceptanceBaselineSeeder::StateConflict) {
                throw new RuntimeException(
                    'The selected scheduling scenario found partial, conflicting, or another scenario\'s operational data. No writes were made; use the approved snapshot-and-rebuild procedure before replacing persistent test data.',
                );
            }

            if ($state === ClientAlignedAcceptanceBaselineSeeder::StateEmpty) {
                $seeder->run();
                $outcome = 'created';
                $state = $seeder->state();
            } else {
                $outcome = 'already_present';
            }

            $readiness = $seeder->readinessPasses() ? 'PASS' : 'FAIL';

            if ($readiness === 'FAIL') {
                throw new RuntimeException('The scheduling acceptance scenario failed its input-readiness check.');
            }

            $this->info("TAL-96D2C {$manifest['scenario']} scheduling acceptance scenario ready.");
            $this->writeManifest($outcome, $state, $readiness, $seeder);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function writeManifest(
        string $outcome,
        string $state,
        string $readiness,
        SchedulingAcceptanceScenarioSeeder $seeder,
    ): void {
        $manifest = $seeder->manifest();
        $report = $seeder->inspectionReport();

        $this->line('outcome='.$outcome);
        $this->line('database='.$report['database']);
        $this->line('scenario='.$manifest['scenario']);
        $this->line('scenario_state='.$state);
        $this->line('basis='.$manifest['basis']);
        $this->line('limitation='.$manifest['limitation']);
        $this->line('target_students='.$manifest['counts']['students']);
        $this->line('students='.$report['students']);
        $this->line('cohorts='.$report['cohorts']);
        $this->line('faculty='.$manifest['counts']['faculty']);
        $this->line('client_reported_faculty='.(
            $manifest['faculty_evidence']['client_reported_faculty'] ?? 'NOT_REPORTED'
        ));
        $this->line('synthetic_scheduling_faculty='.$manifest['faculty_evidence']['synthetic_scheduling_faculty']);
        $this->line('total_teaching_units='.$manifest['faculty_evidence']['total_teaching_units']);
        $this->line('arithmetic_faculty_lower_bound='.$manifest['faculty_evidence']['arithmetic_faculty_lower_bound']);
        $this->line('max_units_per_faculty='.$manifest['faculty_evidence']['max_units_per_faculty']);
        $this->line('maximum_constructed_load='.$manifest['faculty_evidence']['maximum_constructed_load']);
        $this->line('faculty_availability_assumption='.$manifest['faculty_evidence']['availability_assumption']);
        $this->line('bounded_faculty_readiness='.$manifest['faculty_evidence']['bounded_readiness']);
        $this->line('unassignable_workloads='.json_encode(
            $manifest['faculty_evidence']['unassignable_workloads'],
            JSON_THROW_ON_ERROR,
        ));
        $this->line('faculty_evidence_interpretation='.$manifest['faculty_evidence']['interpretation']);
        $this->line('term_offerings='.$manifest['counts']['offerings']);
        $this->line('sections='.$manifest['counts']['sections']);
        $this->line('scheduling_demands='.$report['scheduling_demands']);
        $this->line('ready_scheduling_demands='.$report['ready_scheduling_demands']);
        $this->line('operating_grid=MON-SAT 07:00-21:00 Asia/Manila');
        $this->line('readiness='.$readiness);
        $this->line('solver_feasibility='.$manifest['solver_feasibility']);
        $this->line('solver_optimality='.$manifest['solver_optimality']);
    }
}
