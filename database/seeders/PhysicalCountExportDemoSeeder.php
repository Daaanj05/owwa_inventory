<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PhysicalCountExportDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleAndUserSeeder::class,
            ItemCategorySeeder::class,
            DemoDataSeeder::class,
            PhysicalCountExportFixturesSeeder::class,
        ]);
    }
}
