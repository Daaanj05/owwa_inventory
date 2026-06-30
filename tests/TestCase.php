<?php

namespace Tests;

use Database\Seeders\ReferenceSeriesSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $this->enforceIsolatedTestingDatabase();

        $app = parent::createApplication();

        $app->make('config')->set('database.default', 'sqlite');
        $app->make('config')->set('database.connections.sqlite.database', ':memory:');

        return $app;
    }

    protected function enforceIsolatedTestingDatabase(): void
    {
        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
        ] as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->fail(
                'Tests must use the sqlite in-memory database. '.
                'Running tests against '.config('database.default').' runs migrate:fresh and wipes your local MySQL data. '.
                'Copy .env.testing.example to .env.testing or fix tests/TestCase.php.'
            );
        }

        if (Schema::hasTable('reference_series')) {
            $this->seed(ReferenceSeriesSeeder::class);
        }
    }
}
