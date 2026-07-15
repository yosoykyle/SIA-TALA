<?php

namespace App\Actions\Integrations\Payments;

use RuntimeException;
use Throwable;

final class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly bool $retryable,
        public readonly bool $indeterminate,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
