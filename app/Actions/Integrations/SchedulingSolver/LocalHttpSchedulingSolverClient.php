<?php

namespace App\Actions\Integrations\SchedulingSolver;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class LocalHttpSchedulingSolverClient implements SchedulingSolverClient
{
    public function __construct(
        private readonly ?string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
        private readonly string $environment,
    ) {}

    public static function supportsEnvironment(string $environment): bool
    {
        return in_array($environment, ['local', 'testing'], true);
    }

    public static function supportsBaseUrl(?string $baseUrl): bool
    {
        $parts = parse_url(trim((string) $baseUrl));
        $host = is_array($parts)
            ? strtolower(trim((string) ($parts['host'] ?? ''), '[]'))
            : '';
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts)
            && in_array($path, ['', '/'], true);
    }

    public function solve(SchedulingSolverRequest $request): SchedulingSolverResponse
    {
        try {
            $transportStartedAt = hrtime(true);
            $response = $this->request()
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
                'Local scheduling solver request failed.',
            );
        }

        $decodeStartedAt = hrtime(true);
        $payload = $response->json();
        $decodeMs = $this->elapsedMilliseconds($decodeStartedAt);

        if (! is_array($payload)) {
            throw SchedulingSolverTransportException::permanent(
                SchedulingSolverTransportException::ClassificationMalformedResponse,
                'Local scheduling solver did not return a JSON object.',
            );
        }

        return new SchedulingSolverResponse(
            payload: $payload,
            providerRequestId: $this->safeProviderRequestId(
                $response->header('X-TALA-Provider-Request-ID'),
            ),
            timings: [
                'authentication_ms' => 0,
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
            $response = $this->request()
                ->get($this->endpoint('/health'))
                ->throw();
        } catch (SchedulingSolverTransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SchedulingSolverTransportException::fromHttpFailure(
                $exception,
                'Local scheduling solver probe failed.',
            );
        }

        return [
            'status' => $response->status(),
            'body' => trim(substr($response->body(), 0, 500)),
        ];
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(max(1, $this->timeoutSeconds))
            ->connectTimeout(max(1, $this->connectTimeoutSeconds));
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->validatedBaseUrl(), '/').'/'.ltrim($path, '/');
    }

    private function validatedBaseUrl(): string
    {
        if (! self::supportsEnvironment($this->environment)) {
            throw SchedulingSolverTransportException::permanent(
                SchedulingSolverTransportException::ClassificationConfiguration,
                'Local scheduling solver transport is available only in local or testing environments.',
            );
        }

        $baseUrl = trim((string) $this->baseUrl);

        if (! self::supportsBaseUrl($baseUrl)) {
            throw SchedulingSolverTransportException::permanent(
                SchedulingSolverTransportException::ClassificationConfiguration,
                'Local scheduling solver URL must use HTTP on an exact loopback host with no path, credentials, query, or fragment.',
            );
        }

        return rtrim($baseUrl, '/');
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
