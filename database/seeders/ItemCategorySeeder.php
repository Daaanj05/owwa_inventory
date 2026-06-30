<?php

namespace Database\Seeders;

use App\Models\ItemCategory;
use Illuminate\Database\Seeder;

class ItemCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Consumables', 'description' => 'Office consumables and supplies'],
            ['name' => 'Semi-Expendable', 'description' => 'Semi-expendable properties'],
            ['name' => 'Property, Plant and Equipment', 'description' => 'Property, plant and equipment (PPE)'],
        ];

        foreach ($categories as $cat) {
            ItemCategory::firstOrCreate(
                ['name' => $cat['name']],
                ['description' => $cat['description']]
            );
        }
    }
}
