<?php

namespace App\Console\Commands;

use App\Actions\SystemAdministration\AcceptanceBaselineEnvironmentGuard;
use Database\Seeders\ClientAlignedAcceptanceBaselineSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Seeds the Canonical TALA Scheduling Dataset for acceptance journeys.
 *
 * This command has no application UI, is restricted to testing on test_tala_db,
 * and prepares acceptance records without invoking the scheduling solver.
 */
final class SeedClientAcceptanceBaseline extends Command
{
    protected $signature = 'acceptance:seed-client-baseline
        {--check : Inspect the baseline state and readiness without writing}';

    protected $description = 'Inspect or seed the guarded, deterministic client acceptance baseline.';

    public function handle(
        AcceptanceBaselineEnvironmentGuard $environmentGuard,
        ClientAlignedAcceptanceBaselineSeeder $seeder,
    ): int {
        try {
            $environmentGuard->assertSafe();
            $state = $seeder->state();

            if ($this->option('check')) {
                $readiness = $state === ClientAlignedAcceptanceBaselineSeeder::StateComplete
                    && $seeder->readinessPasses()
                        ? 'PASS'
                        : 'NOT_READY';
                $report = $seeder->inspectionReport();

                $this->info('TAL-96D1 client acceptance baseline inspection complete.');
                $this->line('outcome=inspection_only');
                $this->line('database='.$report['database']);
                $this->line('baseline_state='.$state);
                $this->line('readiness='.$readiness);
                $this->line('students='.$report['students']);
                $this->line('cohorts='.$report['cohorts']);
                $this->line('scheduling_demands='.$report['scheduling_demands']);
                $this->line('ready_scheduling_demands='.$report['ready_scheduling_demands']);
                $this->line('admission_requirement_policies='.$report['admission_requirement_policies']);

                foreach ($report['standings'] as $standing => $count) {
                    $this->line('standing_'.str($standing)->snake()->toString().'='.$count);
                }

                $this->line(sprintf(
                    'scenario_anchors=%d/%d',
                    $report['scenario_anchors']['matched'],
                    $report['scenario_anchors']['expected'],
                ));
                $this->line('downstream_state='.$report['downstream_state']);

                foreach ($report['downstream'] as $recordType => $count) {
                    $this->line('downstream_'.$recordType.'='.$count);
                }

                return $readiness === 'PASS' ? self::SUCCESS : self::FAILURE;
            }

            if ($state === ClientAlignedAcceptanceBaselineSeeder::StateConflict) {
                throw new RuntimeException(
                    'The client acceptance baseline found partial or conflicting operational data. This can be normal after demo or UI edits. No writes were made; rebuild test_tala_db before reseeding when a fresh baseline is required.',
                );
            }

            if ($state === ClientAlignedAcceptanceBaselineSeeder::StateEmpty) {
                DB::transaction(fn () => $seeder->run(), 3);
                $outcome = 'created';
            } else {
                $outcome = 'already_present';
            }

            $readiness = $seeder->readinessPasses() ? 'PASS' : 'FAIL';

            if ($readiness === 'FAIL') {
                throw new RuntimeException('The client acceptance baseline failed its live scheduling readiness check.');
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('TAL-96B1 client acceptance baseline ready.');
        $this->line('outcome='.$outcome);
        $this->line('term=AY 2025-2026 / Second Semester');
        $this->line('students=47');
        $this->line('scheduling_demands=54');
        $this->line('admission_requirement_policies='.$seeder->inspectionReport()['admission_requirement_policies']);
        $this->line('readiness='.$readiness);
        $this->warn('Representative test-only logins (password: password):');
        $this->line('applicant=applicant.demo@example.test');
        $this->line('student=student.demo@example.test');
        $this->line('registrar=registrar.demo@example.test');
        $this->line('accounting=accounting.demo@example.test');
        $this->line('faculty=faculty.demo@example.test');
        $this->line('academic-head=academic-head.demo@example.test');
        $this->line('system-super-admin=system-admin.demo@example.test');

        return self::SUCCESS;
    }
}
