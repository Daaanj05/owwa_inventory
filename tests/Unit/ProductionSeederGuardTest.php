<?php

namespace Tests\Unit;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProductionSeederGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_refuses_production_without_allow_flag(): void
    {
        $this->app['env'] = 'production';
        putenv('ALLOW_PRODUCTION_SEED=false');
        $_ENV['ALLOW_PRODUCTION_SEED'] = 'false';
        $_SERVER['ALLOW_PRODUCTION_SEED'] = 'false';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ALLOW_PRODUCTION_SEED=true');

        (new DatabaseSeeder)->run();
    }
}
