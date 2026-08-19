<?php

namespace App\Actions\Integrations\SchedulingSolver;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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

    public function solve(SchedulingSolverRequest $request): SchedulingSolverResponse
    {
        $authenticationStartedAt = hrtime(true);
        try {
            $pendingRequest = $this->authorizedRequest();
            $authenticationMs = $this->elapsedMilliseconds($authenticationStartedAt);
            $transportStartedAt = hrtime(true);
            $response = $pendingRequest
                ->withHeaders([
                    'X-TALA-Solver-Request-ID' => $request->requestId(),
                    'X-TALA-Snapshot-SHA256' => $request->snapshotSha256(),
                ])
                ->withBody($request->json(), 'application/json')
                ->post($this->endpoint('/solve'));
            $transportMs = $this->elapsedMilliseconds($transportStartedAt);
            $this->throwForBudgetExhaustion($response);
            $response->throw();
        } catch (SchedulingSolverTransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SchedulingSolverTransportException::fromHttpFailure(
                $exception,
                'Scheduling solver request failed.',
            );
        }

        $decodeStartedAt = hrtime(true);
        $payload = $response->json();
        $decodeMs = $this->elapsedMilliseconds($decodeStartedAt);

        if (! is_array($payload)) {
            throw SchedulingSolverTransportException::permanent(
                SchedulingSolverTransportException::ClassificationMalformedResponse,
                'Scheduling solver did not return a JSON object.',
            );
        }

        return new SchedulingSolverResponse(
            payload: $payload,
            providerRequestId: $this->safeProviderRequestId(
                $response->header('X-TALA-Provider-Request-ID'),
            ),
            timings: [
                'authentication_ms' => $authenticationMs,
                'transport_ms' => $transportMs,
                'decode_ms' => $decodeMs,
            ],
            solverPhaseTimings: SchedulingSolverResponse::parseSolverPhaseTimings(
                $response->header('X-TALA-Solver-Phase-Timings'),
            ),
        );
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

    private function throwForBudgetExhaustion(Response $response): void
    {
        if ($response->status() === 503
            && $response->json('code') === 'solver_request_budget_exhausted') {
            throw SchedulingSolverTransportException::requestBudgetExceeded(
                providerRequestId: $this->safeProviderRequestId(
                    $response->header('X-TALA-Provider-Request-ID'),
                ),
                solverPhaseTimings: SchedulingSolverResponse::parseSolverPhaseTimings(
                    $response->header('X-TALA-Solver-Phase-Timings'),
                ),
            );
        }
    }

    private function safeProviderRequestId(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/\A[A-Za-z0-9:._-]{1,160}\z/', $value) === 1 ? $value : null;
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
