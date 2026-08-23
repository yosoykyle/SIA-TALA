<?php

namespace App\Actions\Integrations\Payments;

use RuntimeException;

final class PaymentAttemptSnapshotException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The Payment Attempt no longer matches the canonical exact-due snapshot.');
    }
}
