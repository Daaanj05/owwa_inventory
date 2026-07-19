<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->environment('production') && ! filter_var(env('ALLOW_PRODUCTION_SEED', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException(
                'Refusing to seed in production without ALLOW_PRODUCTION_SEED=true.',
            );
        }

        $this->call([
            ItemCategorySeeder::class,
            RoleAndUserSeeder::class,
            ReferenceSeriesSeeder::class,
            UacsObjectCodeSeeder::class,
        ]);
    }
}
