<?php

declare(strict_types=1);

namespace App\Support;

use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class SafeUserNotification
{
    public const DefaultFallback = 'Something went wrong while completing your request. Your data has not been changed. Try again or contact support.';

    public const ValidationErrorTitle = 'Check the highlighted fields';

    public const ValidationErrorBody = 'Fix the highlighted fields and try again.';

    /**
     * Send a reusable general validation toast when inline input errors are already displayed.
     */
    public static function sendValidationError(
        string $title = self::ValidationErrorTitle,
        string $body = self::ValidationErrorBody,
        bool $persistent = false,
    ): void {
        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->danger();

        if ($persistent) {
            $notification->persistent();
        }

        $notification->send();
    }

    /**
     * Resolve a safe, user-facing failure message for UI notifications.
     * Unexpected system exceptions are reported and replaced with fallback copy.
     */
    public static function message(Throwable $exception, string $fallback = self::DefaultFallback): string
    {
        if ($exception instanceof ValidationException) {
            return (string) (collect($exception->errors())->flatten()->first() ?: $exception->getMessage() ?: $fallback);
        }

        if ($exception instanceof AuthorizationException || $exception instanceof DomainException) {
            $message = trim($exception->getMessage());

            return $message !== '' ? $message : $fallback;
        }

        if ($exception::class === RuntimeException::class || self::isExplicitDomainRuntimeException($exception)) {
            $message = trim($exception->getMessage());

            if ($message !== '' && ! self::looksLikeInternalDiagnostic($message)) {
                return $message;
            }
        }

        report($exception);

        return $fallback;
    }

    /**
     * Send a standardized danger notification without exposing raw system internals.
     */
    public static function sendError(
        string $title,
        Throwable $exception,
        string $fallback = self::DefaultFallback,
        bool $persistent = false,
    ): void {
        $notification = Notification::make()
            ->title($title)
            ->body(self::message($exception, $fallback))
            ->danger();

        if ($persistent) {
            $notification->persistent();
        }

        $notification->send();
    }

    private static function isExplicitDomainRuntimeException(Throwable $exception): bool
    {
        return str_starts_with($exception::class, 'App\\Actions\\')
            && $exception instanceof RuntimeException;
    }

    private static function looksLikeInternalDiagnostic(string $message): bool
    {
        $patterns = [
            '/(?:SQLSTATE\[|Integrity constraint violation|Undefined\s+|Call to\s+|Stack trace:|connection string|PDOException|QueryException|Connection refused|Access denied for user|Base table or view not found|Column not found|Unknown column|Class\s+["\']?[^"\'\s]+["\']?\s+not found|Attempt to read property|Attempt to assign property|Cannot access)/i',
            '/(?:mysql|pgsql|postgresql|sqlsrv|sqlite|redis|rediss|mongodb(?:\+srv)?|amqps?|smtp|ldaps?|tcp|ssl|tls):\/\/|[a-z][a-z0-9+.-]*:\/\/[^\/\s]+@/i',
            '/(?:App|Illuminate|Symfony|Filament|Livewire|League|Spatie)\\\\[A-Za-z0-9_\\\\]+/',
            '/(?:[A-Za-z]:\\\\|\/(?:var|home|Users|app|vendor|etc|srv|opt)\/|(?i:\.(?:php|sql|env)\b))/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
