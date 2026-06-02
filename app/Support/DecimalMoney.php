<?php

namespace App\Support;

use InvalidArgumentException;

class DecimalMoney
{
    public function normalize(string|int|float $amount): string
    {
        return $this->fromCents($this->toCents($amount));
    }

    public function add(string|int|float $left, string|int|float $right): string
    {
        return $this->fromCents($this->toCents($left) + $this->toCents($right));
    }

    public function subtract(string|int|float $left, string|int|float $right): string
    {
        return $this->fromCents($this->toCents($left) - $this->toCents($right));
    }

    public function multiplyPercent(string|int|float $amount, string|int|float $percent): string
    {
        $amountCents = $this->toCents($amount);
        $percentBasisPoints = $this->toBasisPoints($percent);

        $numerator = $amountCents * $percentBasisPoints;
        $rounded = $numerator >= 0
            ? intdiv($numerator + 5000, 10000)
            : intdiv($numerator - 5000, 10000);

        return $this->fromCents($rounded);
    }

    public function isZeroOrNegative(string|int|float $amount): bool
    {
        return $this->toCents($amount) <= 0;
    }

    public function greaterThanZero(string|int|float $amount): bool
    {
        return $this->toCents($amount) > 0;
    }

    public function min(string|int|float $left, string|int|float $right): string
    {
        return $this->toCents($left) <= $this->toCents($right)
            ? $this->normalize($left)
            : $this->normalize($right);
    }

    public function toCents(string|int|float $amount): int
    {
        $normalized = str_replace(',', '', trim((string) $amount));

        if ($normalized === '') {
            throw new InvalidArgumentException('Amount cannot be empty.');
        }

        $negative = str_starts_with($normalized, '-');
        $unsigned = $negative ? substr($normalized, 1) : $normalized;

        if (! preg_match('/^\d+(\.\d{1,4})?$/', $unsigned)) {
            throw new InvalidArgumentException("Invalid decimal amount [{$amount}].");
        }

        $parts = explode('.', $unsigned);
        $whole = (int) $parts[0];
        $fractionRaw = $parts[1] ?? '0';
        $fractionPadded = str_pad($fractionRaw, 3, '0');
        $fractionForRounding = (int) substr($fractionPadded, 0, 3);

        $fractionTwoDigits = intdiv($fractionForRounding, 10);
        $roundUp = ($fractionForRounding % 10) >= 5;

        if ($roundUp) {
            $fractionTwoDigits += 1;
        }

        if ($fractionTwoDigits >= 100) {
            $whole += 1;
            $fractionTwoDigits -= 100;
        }

        $cents = ($whole * 100) + $fractionTwoDigits;

        return $negative ? -$cents : $cents;
    }

    public function fromCents(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $whole = intdiv($absolute, 100);
        $fraction = $absolute % 100;
        $formatted = sprintf('%d.%02d', $whole, $fraction);

        return $negative ? "-{$formatted}" : $formatted;
    }

    private function toBasisPoints(string|int|float $percent): int
    {
        $normalized = str_replace(',', '', trim((string) $percent));

        if ($normalized === '') {
            throw new InvalidArgumentException('Percent cannot be empty.');
        }

        $negative = str_starts_with($normalized, '-');
        $unsigned = $negative ? substr($normalized, 1) : $normalized;

        if (! preg_match('/^\d+(\.\d{1,4})?$/', $unsigned)) {
            throw new InvalidArgumentException("Invalid percentage [{$percent}].");
        }

        $parts = explode('.', $unsigned);
        $whole = (int) $parts[0];
        $fraction = str_pad($parts[1] ?? '0', 2, '0');
        $basisPoints = ($whole * 100) + (int) substr($fraction, 0, 2);

        return $negative ? -$basisPoints : $basisPoints;
    }
}
