<?php

namespace App\Actions\Integrations\Payments;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PayMongoSandboxEnvironmentGuard
{
    /** @param list<string> $requiredConfiguration */
    public function assertSafe(array $requiredConfiguration = []): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('PayMongo sandbox commands require APP_ENV=testing.');
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== 'test_tala_db') {
            throw new RuntimeException('PayMongo sandbox commands require MySQL database test_tala_db.');
        }

        if (config('tala_integrations.payments.driver') !== 'paymongo') {
            throw new RuntimeException('PayMongo payment driver is required for sandbox commands.');
        }

        if ((bool) config('tala_integrations.payments.paymongo.livemode')) {
            throw new RuntimeException('PayMongo live mode is not allowed for sandbox commands.');
        }

        $baseUrl = trim((string) config('tala_integrations.payments.paymongo.base_url'));

        if (strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https'
            || strtolower((string) parse_url($baseUrl, PHP_URL_HOST)) !== 'api.paymongo.com') {
            throw new RuntimeException('The official HTTPS PayMongo API endpoint is required for sandbox commands.');
        }

        foreach ($requiredConfiguration as $configurationKey) {
            $value = trim((string) config('tala_integrations.payments.paymongo.'.$configurationKey));

            if ($value === '') {
                throw new RuntimeException('The required PayMongo sandbox configuration is missing.');
            }

            if ($configurationKey === 'secret_key' && ! str_starts_with($value, 'sk_test_')) {
                throw new RuntimeException('A PayMongo test-mode secret key is required for sandbox commands.');
            }

            if ($configurationKey === 'public_key' && ! str_starts_with($value, 'pk_test_')) {
                throw new RuntimeException('A PayMongo test-mode public key is required for sandbox commands.');
            }
        }
    }
}
