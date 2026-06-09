<?php

namespace App\Actions\Integrations\SchedulingSolver;

class LocalStubSchedulingSolverClient implements SchedulingSolverClient
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function solve(array $snapshot): array
    {
        return [
            'solver_status' => 'local_stub',
            'assigned_count' => 0,
            'unassigned_count' => 0,
            'hard_violation_count' => 0,
            'warning_count' => 0,
            'timeout' => false,
            'draft_rows' => [],
            'snapshot_received' => $snapshot !== [],
        ];
    }

    /**
     * @return array{status:int, body:string}
     */
    public function probe(): array
    {
        return [
            'status' => 200,
            'body' => 'local_stub',
        ];
    }
}
