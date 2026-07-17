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

        if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== 'test_tala_db') {
            throw new RuntimeException('The client acceptance baseline requires MySQL database test_tala_db.');
        }
    }
}
