<?php

namespace App\Actions\Scheduling;

use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SchedulingBenchmarkSnapshotCapture
{
    public function __construct(
        private readonly ScheduleGenerationService $generationService,
    ) {}

    /**
     * @return array{run:ScheduleGenerationRun,snapshot:array<string,mixed>}
     */
    public function capture(): array
    {
        $startingTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $term = Term::query()->where('type', Term::TypeSecondSemester)->sole();
            $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();
            $run = $this->generationService->generate($term, $registrar);
            $snapshot = $run->getAttribute('input_snapshot');

            if (! is_array($snapshot)
                || ($snapshot['contract_version'] ?? null) !== 'tal94-demand-v2') {
                throw new RuntimeException('The representative scheduling snapshot is not TAL-94 V2.');
            }
        } finally {
            while (DB::transactionLevel() > $startingTransactionLevel) {
                DB::rollBack();
            }
        }

        return [
            'run' => $run,
            'snapshot' => $snapshot,
        ];
    }
}
