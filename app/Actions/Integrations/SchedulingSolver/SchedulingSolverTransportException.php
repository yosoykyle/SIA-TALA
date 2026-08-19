<?php

namespace App\Actions\Integrations\SchedulingSolver;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Throwable;

final class SchedulingSolverTransportException extends RuntimeException
{
    public const ClassificationConnection = 'connection';

    public const ClassificationTimeout = 'timeout';

    public const ClassificationRateLimited = 'rate_limited';

    public const ClassificationServerError = 'server_error';

    public const ClassificationClientError = 'client_error';

    public const ClassificationConfiguration = 'configuration';

    public const ClassificationCredential = 'credential';

    public const ClassificationMalformedResponse = 'malformed_response';

    public const ClassificationMalformedRequest = 'malformed_request';

    public const ClassificationRequestBudget = 'request_budget_exhausted';

    public const ClassificationUnexpected = 'unexpected';

    /** @param  array<string, mixed>  $safeContext */
    private function __construct(
        string $message,
        private readonly string $failureClassification,
        private readonly bool $retryable,
        private readonly ?int $httpStatusCode = null,
        private readonly array $safeContext = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function retryable(
        string $classification,
        string $message,
        ?int $statusCode = null,
        ?Throwable $previous = null,
    ): self {
        return new self($message, $classification, true, $statusCode, [], $previous);
    }

    public static function permanent(
        string $classification,
        string $message,
        ?int $statusCode = null,
        ?Throwable $previous = null,
    ): self {
        return new self($message, $classification, false, $statusCode, [], $previous);
    }

    /** @param  array<string, int>  $solverPhaseTimings */
    public static function requestBudgetExceeded(
        ?string $providerRequestId,
        array $solverPhaseTimings,
    ): self {
        return new self(
            'Scheduling solver exhausted its bounded request budget.',
            self::ClassificationRequestBudget,
            true,
            503,
            [
                'provider_request_id' => $providerRequestId,
                'solver_phase_ms' => $solverPhaseTimings,
            ],
        );
    }

    public static function fromHttpFailure(Throwable $exception, string $message): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        if ($exception instanceof ConnectionException) {
            $classification = str_contains(strtolower($exception->getMessage()), 'timed out')
                || str_contains(strtolower($exception->getMessage()), 'timeout')
                    ? self::ClassificationTimeout
                    : self::ClassificationConnection;

            return self::retryable($classification, $message, previous: $exception);
        }

        if ($exception instanceof RequestException) {
            $statusCode = $exception->response->status();

            return match (true) {
                $statusCode === 408 => self::retryable(self::ClassificationTimeout, $message, $statusCode, $exception),
                $statusCode === 429 => self::retryable(self::ClassificationRateLimited, $message, $statusCode, $exception),
                $statusCode >= 500 => self::retryable(self::ClassificationServerError, $message, $statusCode, $exception),
                default => self::permanent(self::ClassificationClientError, $message, $statusCode, $exception),
            };
        }

        return self::permanent(self::ClassificationUnexpected, $message, previous: $exception);
    }

    public static function unexpected(Throwable $exception): self
    {
        return self::permanent(
            self::ClassificationUnexpected,
            'Scheduling solver dispatch failed unexpectedly.',
            previous: $exception,
        );
    }

    public function classification(): string
    {
        return $this->failureClassification;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function statusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function safeDiagnostics(): array
    {
        return [
            'classification' => $this->classification(),
            'retryable' => $this->isRetryable(),
            'status_code' => $this->statusCode(),
            ...$this->safeContext,
        ];
    }
}
