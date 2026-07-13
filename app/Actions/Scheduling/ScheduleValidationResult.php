<?php

namespace App\Actions\Scheduling;

final readonly class ScheduleValidationResult
{
    /**
     * @param  list<array<string, mixed>>  $candidateRows
     * @param  list<array<string, mixed>>  $findings
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        private array $candidateRows,
        private array $findings,
        private array $metadata,
        private array $summary,
    ) {}

    public function passes(): bool
    {
        return $this->blockingFindings() === [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function candidateRows(): array
    {
        return $this->candidateRows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blockingFindings(): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (array $finding): bool => ($finding['severity'] ?? null) === 'blocking',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->summary;
    }
}
