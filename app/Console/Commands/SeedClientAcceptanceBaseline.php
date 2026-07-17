<?php

namespace App\Console\Commands;

use App\Actions\SystemAdministration\AcceptanceBaselineEnvironmentGuard;
use Database\Seeders\ClientAlignedAcceptanceBaselineSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class SeedClientAcceptanceBaseline extends Command
{
    protected $signature = 'acceptance:seed-client-baseline';

    protected $description = 'Seed the guarded, deterministic TAL-96B1 client acceptance baseline.';

    public function handle(
        AcceptanceBaselineEnvironmentGuard $environmentGuard,
        ClientAlignedAcceptanceBaselineSeeder $seeder,
    ): int {
        try {
            $environmentGuard->assertSafe();
            $state = $seeder->state();

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
