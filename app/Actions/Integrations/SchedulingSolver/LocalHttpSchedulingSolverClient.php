<?php

namespace App\Actions\Integrations\SchedulingSolver;

use Illuminate\Http\Client\PendingRequest;
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

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function solve(array $snapshot): array
    {
        try {
            $response = $this->request()
                ->post($this->endpoint('/solve'), $snapshot)
                ->throw();
        } catch (SchedulingSolverTransportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SchedulingSolverTransportException::fromHttpFailure(
                $exception,
                'Local scheduling solver request failed.',
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw SchedulingSolverTransportException::permanent(
                SchedulingSolverTransportException::ClassificationMalformedResponse,
                'Local scheduling solver did not return a JSON object.',
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
}
