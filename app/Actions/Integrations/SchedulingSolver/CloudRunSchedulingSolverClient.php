<?php

namespace App\Actions\Integrations\SchedulingSolver;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CloudRunSchedulingSolverClient implements SchedulingSolverClient
{
    public function __construct(
        private readonly CloudRunIdTokenProvider $idTokenProvider,
        private readonly ?string $baseUrl,
        private readonly ?string $audience,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function solve(array $snapshot): array
    {
        $endpoint = $this->endpoint('/solve');

        try {
            $response = $this->authorizedRequest()
                ->post($endpoint, $snapshot)
                ->throw();
        } catch (Throwable $exception) {
            throw new RuntimeException('Scheduling solver request failed.', 0, $exception);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Scheduling solver did not return a JSON object.');
        }

        return $payload;
    }

    /**
     * @return array{status:int, body:string}
     */
    public function probe(): array
    {
        $baseUrl = $this->baseUrl();

        try {
            $response = $this->authorizedRequest()
                ->get($baseUrl)
                ->throw();
        } catch (Throwable $exception) {
            throw new RuntimeException('Scheduling solver probe failed.', 0, $exception);
        }

        return [
            'status' => $response->status(),
            'body' => trim(substr($response->body(), 0, 500)),
        ];
    }

    private function authorizedRequest(): PendingRequest
    {
        return Http::withToken($this->idTokenProvider->tokenFor($this->audience()))
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds())
            ->connectTimeout($this->connectTimeoutSeconds());
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl(), '/').'/'.ltrim($path, '/');
    }

    private function baseUrl(): string
    {
        $baseUrl = trim((string) $this->baseUrl);

        if ($baseUrl === '') {
            throw new RuntimeException('Scheduling solver URL is not configured.');
        }

        return $baseUrl;
    }

    private function audience(): string
    {
        $audience = trim((string) $this->audience);

        if ($audience === '') {
            throw new RuntimeException('Scheduling solver audience is not configured.');
        }

        return $audience;
    }

    private function timeoutSeconds(): int
    {
        return max(1, $this->timeoutSeconds);
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, $this->connectTimeoutSeconds);
    }
}
