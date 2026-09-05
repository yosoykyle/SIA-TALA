<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = require __DIR__.'/../bootstrap/app.php';

        $application->make(Kernel::class)->bootstrap();

        if ($application->environment() !== 'testing'
            || $application['config']->get('database.default') !== 'mysql'
            || $application['config']->get('database.connections.mysql.database') !== 'test_tala_db') {
            throw new LogicException('Tests must run with APP_ENV=testing, DB_CONNECTION=mysql, and DB_DATABASE=test_tala_db.');
        }

        return $application;
    }
}
