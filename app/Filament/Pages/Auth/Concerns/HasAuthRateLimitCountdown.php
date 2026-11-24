<?php

namespace App\Filament\Pages\Auth\Concerns;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

trait HasAuthRateLimitCountdown
{
    public ?int $rateLimitedUntil = null;

    public int $rateLimitRemainingSeconds = 0;

    public int $rateLimitTotalSeconds = 60;

    public string $rateLimitContext = 'login';

    public function startAuthRateLimitCountdown(
        int $secondsUntilAvailable,
        string $context = 'login',
        int $totalSeconds = 60,
    ): void {
        $remaining = max(1, $secondsUntilAvailable);

        $this->rateLimitTotalSeconds = max($totalSeconds, $remaining);
        $this->rateLimitRemainingSeconds = $remaining;
        $this->rateLimitedUntil = now()->addSeconds($remaining)->getTimestamp();
        $this->rateLimitContext = $context;
    }

    public function clearAuthRateLimit(): void
    {
        $this->rateLimitedUntil = null;
        $this->rateLimitRemainingSeconds = 0;
    }

    public function isAuthRateLimited(): bool
    {
        if ($this->rateLimitedUntil !== null) {
            $remaining = $this->rateLimitedUntil - now()->getTimestamp();

            if ($remaining <= 0) {
                $this->clearAuthRateLimit();

                return false;
            }

            $this->rateLimitRemainingSeconds = $remaining;

            return true;
        }

        if ($this->rateLimitRemainingSeconds > 0) {
            $this->rateLimitedUntil = now()->addSeconds($this->rateLimitRemainingSeconds)->getTimestamp();

            return true;
        }

        return false;
    }

    protected function checkInitialRateLimitOnMount(
        string $rateLimitingKey,
        int $maxAttempts,
        string $context,
        int $totalSeconds = 60,
    ): void {
        if (RateLimiter::tooManyAttempts($rateLimitingKey, $maxAttempts)) {
            $this->startAuthRateLimitCountdown(
                secondsUntilAvailable: RateLimiter::availableIn($rateLimitingKey),
                context: $context,
                totalSeconds: $totalSeconds,
            );

            return;
        }

        $this->isAuthRateLimited();
    }

    public function getRateLimitCountdownContentComponent(): Component
    {
        return View::make('components.tala-rate-limit-countdown')
            ->key('auth-rate-limit-countdown')
            ->viewData(fn (): array => [
                'remainingSeconds' => $this->rateLimitRemainingSeconds,
                'totalSeconds' => $this->rateLimitTotalSeconds,
                'context' => $this->rateLimitContext,
                'showUseAnotherAccount' => $this->shouldShowRateLimitUseAnotherAccount(),
            ])
            ->visible(fn (): bool => $this->isAuthRateLimited());
    }

    protected function shouldShowRateLimitUseAnotherAccount(): bool
    {
        return false;
    }

    protected function formatRateLimitRemainingWarning(int $remainingAttempts, int $cooldownSeconds = 60): ?string
    {
        return match ($remainingAttempts) {
            2 => "2 attempts remaining before a {$cooldownSeconds}-second pause.",
            1 => "1 attempt remaining before a {$cooldownSeconds}-second pause.",
            default => null,
        };
    }

    protected function appendRateLimitRemainingWarning(
        ValidationException $exception,
        int $remainingAttempts,
        int $cooldownSeconds = 60,
    ): ValidationException {
        $warning = $this->formatRateLimitRemainingWarning($remainingAttempts, $cooldownSeconds);

        if ($warning === null) {
            return $exception;
        }

        $messages = $exception->errors();

        foreach ($messages as $field => $fieldMessages) {
            if ($fieldMessages === []) {
                continue;
            }

            if (! str_contains($fieldMessages[0], $warning)) {
                $messages[$field][0] = rtrim($fieldMessages[0]).' '.$warning;
            }
        }

        return ValidationException::withMessages($messages);
    }
}
