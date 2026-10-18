<?php

namespace App\Actions\SystemAdministration;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AcceptanceBaselineEnvironmentGuard
{
    public function assertSafe(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('The client acceptance baseline requires APP_ENV=testing.');
        }

        $connection = DB::connection();
        $expectedDatabase = (string) config('database.test_database', 'test_tala_db');

        if (preg_match('/^test_tala_db(?:_[a-z0-9_]+)?$/', $expectedDatabase) !== 1
            || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== $expectedDatabase
            || ($connection->selectOne('SELECT DATABASE() AS name')->name ?? null) !== $expectedDatabase) {
            throw new RuntimeException('The client acceptance baseline requires the explicit MySQL test_tala_db or isolated TALA_TEST_DATABASE target.');
        }
    }
}
