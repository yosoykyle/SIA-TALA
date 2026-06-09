<?php

namespace App\Actions\Integrations\SchedulingSolver;

interface SchedulingSolverClient
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function solve(array $snapshot): array;

    /**
     * @return array{status:int, body:string}
     */
    public function probe(): array;
}
