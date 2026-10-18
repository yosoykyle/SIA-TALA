<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        $expectedDatabase = (string) env('TALA_TEST_DATABASE', 'test_tala_db');

        if ($application->environment() !== 'testing'
            || $application['config']->get('database.default') !== 'mysql'
            || preg_match('/^test_tala_db(?:_[a-z0-9_]+)?$/', $expectedDatabase) !== 1
            || $application['config']->get('database.connections.mysql.database') !== $expectedDatabase) {
            throw new LogicException('Tests require APP_ENV=testing, MySQL, and the explicit test_tala_db database or isolated TALA_TEST_DATABASE target.');
        }

        return $application;
    }
}
