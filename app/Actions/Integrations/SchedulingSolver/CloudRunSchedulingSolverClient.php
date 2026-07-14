<?php

namespace App\Actions\Integrations\SchedulingSolver;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
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
        try {
            $response = $this->authorizedRequest()
                ->post($this->endpoint('/solve'), $snapshot)
                ->throw();
        } catch (SchedulingSolverTransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SchedulingSolverTransportException::fromHttpFailure(
                $exception,
                'Scheduling solver request failed.',
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw SchedulingSolverTransportException::permanent(
                SchedulingSolverTransportException::ClassificationMalformedResponse,
                'Scheduling solver did not return a JSON object.',
            );
        }

        return $payload;
    }

    /**
     * @return array{status:int, body:string}
     */
    public function probe(): array
    {
        try {
            $response = $this->authorizedRequest()
                ->get($this->endpoint('/health'))
                ->throw();
        } catch (SchedulingSolverTransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SchedulingSolverTransportException::fromHttpFailure(
                $exception,
                'Scheduling solver probe failed.',
            );
        }

        return [
            'status' => $response->status(),
            'body' => trim(substr($response->body(), 0, 500)),
        ];
    }

    private function authorizedRequest(): PendingRequest
    {
        $audience = $this->audience();

        try {
            $token = $this->idTokenProvider->tokenFor($audience);
        } catch (Throwable $exception) {
            throw SchedulingSolverTransportException::permanent(
                SchedulingSolverTransportException::ClassificationCredential,
                'Scheduling solver authentication failed.',
                previous: $exception,
            );
        }

        return Http::withToken($token)
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
            throw SchedulingSolverTransportException::permanent(
                SchedulingSolverTransportException::ClassificationConfiguration,
                'Scheduling solver URL is not configured.',
            );
        }

        return $baseUrl;
    }

    private function audience(): string
    {
        $audience = trim((string) $this->audience);

        if ($audience === '') {
            throw SchedulingSolverTransportException::permanent(
                SchedulingSolverTransportException::ClassificationConfiguration,
                'Scheduling solver audience is not configured.',
            );
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
