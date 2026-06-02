<?php

namespace App\Actions\Calendar\Exceptions;

use RuntimeException;

class CalendarGateViolation extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly string $gate,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
