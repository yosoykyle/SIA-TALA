<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FAQRCode\Google2FA;
use RuntimeException;

class BrowserQualificationEnvironment
{
    public const TargetDatabase = 'test_tala_db';

    public const SimulateHealthFailureCacheKey = 'tala:qualification:simulate_health_capture_failure';

    public const SimulateHealthFailureFlagFile = __DIR__.'/../../storage/framework/test_health_capture_failure.flag';

    public static function enableHealthCaptureFailure(int $ttlSeconds = 30): void
    {
        self::assertValidDatabase();
        @file_put_contents(self::SimulateHealthFailureFlagFile, (string) (time() + $ttlSeconds));
        try {
            Cache::put(self::SimulateHealthFailureCacheKey, true, now()->addSeconds($ttlSeconds));
        } catch (\Throwable) {
        }
    }

    public static function disableHealthCaptureFailure(): void
    {
        self::assertValidDatabase();
        if (file_exists(self::SimulateHealthFailureFlagFile)) {
            @unlink(self::SimulateHealthFailureFlagFile);
        }
        try {
            Cache::forget(self::SimulateHealthFailureCacheKey);
        } catch (\Throwable) {
        }
    }

    public static function isHealthCaptureFailureEnabled(): bool
    {
        if (file_exists(self::SimulateHealthFailureFlagFile)) {
            $expiresAt = (int) @file_get_contents(self::SimulateHealthFailureFlagFile);
            if ($expiresAt > time()) {
                return true;
            }
            @unlink(self::SimulateHealthFailureFlagFile);
        }

        try {
            return (bool) Cache::get(self::SimulateHealthFailureCacheKey, false);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function assertValidDatabase(?string $databaseName = null, ?string $connection = null): void
    {
        $connection = $connection ?? (string) config('database.default');
        $databaseName = $databaseName ?? (string) config("database.connections.{$connection}.database");

        if ($databaseName !== self::TargetDatabase) {
            throw new RuntimeException("Refusing to seed fixtures: database must be exactly '".self::TargetDatabase."', got '{$databaseName}' on connection '{$connection}'.");
        }
    }

    public static function clearReplayCache(string $secret = 'JBSWY3DPEHPK3PXP', string $adminEmail = 'admin@example.test'): void
    {
        self::assertValidDatabase();

        $g2fa = app(Google2FA::class);
        $ts = $g2fa->getTimestamp();
        $prefix = (string) config('cache.prefix');

        $keys = [];
        for ($i = -10; $i <= 10; $i++) {
            $code = $g2fa->oathTotp($secret, $ts + $i);
            $key = 'filament.app_authentication_codes.'.md5($secret.$code);
            $keys[] = $key;
            $keys[] = $prefix.$key;
        }
        DB::table('cache')->whereIn('key', $keys)->delete();

        $adminId = User::where('email', $adminEmail)->value('id');
        if ($adminId) {
            RateLimiter::clear("tala:system-health:mail-self-test:{$adminId}");
            $mailKeys = [
                "tala:system-health:mail-self-test:{$adminId}",
                "tala:system-health:mail-self-test:{$adminId}:timer",
                $prefix."tala:system-health:mail-self-test:{$adminId}",
                $prefix."tala:system-health:mail-self-test:{$adminId}:timer",
            ];
            DB::table('cache')->whereIn('key', $mailKeys)->delete();
        }

        $testEmails = [
            'admin@example.test',
            'registrar.test@example.test',
            'accounting.test@example.test',
            'faculty.test@example.test',
            'ahead.test@example.test',
            'applicant.test@example.test',
            'applicant.empty@example.test',
            'student.test@example.test',
        ];
        foreach ($testEmails as $email) {
            RateLimiter::clear("tala-login:{$email}|127.0.0.1");
        }
        $users = User::query()->whereIn('email', $testEmails)->get();
        foreach ($users as $user) {
            RateLimiter::clear("tala-mfa:{$user->id}|127.0.0.1");
        }
    }
}
