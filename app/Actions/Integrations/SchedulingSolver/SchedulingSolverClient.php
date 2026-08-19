<?php

namespace App\Actions\Integrations\SchedulingSolver;

interface SchedulingSolverClient
{
    /** @throws SchedulingSolverTransportException */
    public function solve(SchedulingSolverRequest $request): SchedulingSolverResponse;

    /**
     * @return array{status:int, body:string}
     */
    public function probe(): array;
}
