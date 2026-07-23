<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Runs a selected TAL-96D2C scheduling acceptance fixture as one transaction.
 *
 * This acceptance/UAT wrapper delegates record construction and inspection to
 * the baseline seeder; it is not an application startup or production seeder.
 */
final class SchedulingAcceptanceScenarioSeeder extends Seeder
{
    public function __construct(
        private readonly ClientAlignedAcceptanceBaselineSeeder $baselineSeeder,
    ) {}

    public function forScenario(string $scenario): self
    {
        $this->baselineSeeder->forScenario($scenario);

        return $this;
    }

    public function state(): string
    {
        return $this->baselineSeeder->state();
    }

    public function run(): void
    {
        DB::transaction(fn () => $this->baselineSeeder->run(), attempts: 1);
    }

    public function readinessPasses(): bool
    {
        return $this->baselineSeeder->readinessPasses();
    }

    /** @return array<string, mixed> */
    public function inspectionReport(): array
    {
        return $this->baselineSeeder->inspectionReport();
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->baselineSeeder->manifest();
    }
}
