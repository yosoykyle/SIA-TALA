<?php

namespace App\Actions\Integrations\SchedulingSolver;

use JsonException;

final class SchedulingSolverRequest
{
    private string $json;

    /** @param array<string, mixed> $snapshot */
    public function __construct(
        private readonly array $snapshot,
        private readonly string $requestId,
    ) {
        $this->json = $this->encode($snapshot);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function json(): string
    {
        return $this->json;
    }

    public function snapshotSha256(): string
    {
        return hash('sha256', $this->json);
    }

    /** @param array<string, mixed> $snapshot */
    private function encode(array $snapshot): string
    {
        try {
            return json_encode(
                $snapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw SchedulingSolverTransportException::permanent(
                SchedulingSolverTransportException::ClassificationMalformedRequest,
                'Scheduling solver snapshot could not be encoded.',
                previous: $exception,
            );
        }
    }
}
