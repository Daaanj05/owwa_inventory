<?php

namespace Tests;

use Database\Seeders\ReferenceSeriesSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('reference_series')) {
            $this->seed(ReferenceSeriesSeeder::class);
        }
    }
}
