<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonSerializable;
use Stringable;

class ActivityPropertiesFormatter
{
    /**
     * @return list<string>
     */
    public static function lines(mixed $properties): array
    {
        $payload = self::payload($properties);

        if ($payload === []) {
            return ['No additional audit metadata.'];
        }

        return collect(Arr::dot($payload))
            ->map(fn (mixed $value, string $key): string => self::label($key).': '.self::value($value))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(mixed $properties): array
    {
        if ($properties instanceof Collection) {
            $properties = $properties->all();
        }

        if ($properties instanceof JsonSerializable) {
            $properties = $properties->jsonSerialize();
        }

        if ($properties instanceof Stringable) {
            $properties = (string) $properties;
        }

        if (is_string($properties)) {
            $decoded = json_decode($properties, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($properties) ? $properties : [];
    }

    private static function label(string $key): string
    {
        return collect(explode('.', $key))
            ->map(fn (string $segment): string => is_numeric($segment)
                ? '#'.((int) $segment + 1)
                : Str::of($segment)->replace('_', ' ')->headline()->replaceMatches('/\bId\b/', 'ID')->toString())
            ->implode(' > ');
    }

    private static function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not provided';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : 'Not provided';
    }
}
