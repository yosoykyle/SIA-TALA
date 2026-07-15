<?php

namespace App\Actions\Integrations\Payments;

use RuntimeException;

final class PaymentCheckoutException extends RuntimeException
{
    public static function unavailable(string $message): self
    {
        return new self($message);
    }
}
