<?php

namespace App\Actions\Integrations\SchedulingSolver;

final class SchedulingSolverResponse
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array{authentication_ms:int,transport_ms:int,decode_ms:int}  $timings
     * @param  array<string, int>  $solverPhaseTimings
     */
    public function __construct(
        private readonly array $payload,
        private readonly ?string $providerRequestId,
        private readonly array $timings,
        private readonly array $solverPhaseTimings = [],
    ) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function providerRequestId(): ?string
    {
        return $this->providerRequestId;
    }

    /** @return array{authentication_ms:int,transport_ms:int,decode_ms:int} */
    public function timings(): array
    {
        return $this->timings;
    }

    /** @return array<string, int> */
    public function solverPhaseTimings(): array
    {
        return $this->solverPhaseTimings;
    }

    /** @return array<string, int> */
    public static function parseSolverPhaseTimings(?string $header): array
    {
        $decoded = json_decode((string) $header, true);

        if (! is_array($decoded) || count($decoded) > 32) {
            return [];
        }

        $timings = [];

        foreach ($decoded as $phase => $milliseconds) {
            if (! is_string($phase)
                || preg_match('/\A[a-z0-9_]{1,80}\z/', $phase) !== 1
                || ! is_int($milliseconds)
                || $milliseconds < 0
                || $milliseconds > 300_000) {
                return [];
            }

            $timings[$phase] = $milliseconds;
        }

        return $timings;
    }
}
