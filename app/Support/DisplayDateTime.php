<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class DisplayDateTime
{
    public static function format(
        ?DateTimeInterface $value,
        string $format = 'M j, Y, g:i A',
        string $fallback = 'Not recorded',
    ): string {
        if ($value === null) {
            return $fallback;
        }

        return CarbonImmutable::instance($value)
            ->setTimezone((string) config('app.display_timezone', 'Asia/Manila'))
            ->format($format);
    }
}
