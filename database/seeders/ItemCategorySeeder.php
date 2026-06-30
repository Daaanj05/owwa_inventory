<?php

namespace Database\Seeders;

use App\Models\ItemCategory;
use Illuminate\Database\Seeder;

class ItemCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Power Plant Equipment', 'description' => 'Equipment for power plant operations'],
            ['name' => 'Semi-Expendable', 'description' => 'Semi-expendable properties'],
            ['name' => 'Consumables', 'description' => 'Office consumables and supplies'],
            ['name' => 'PPE', 'description' => 'Property, plant and equipment'],
        ];

        foreach ($categories as $cat) {
            ItemCategory::firstOrCreate(
                ['name' => $cat['name']],
                ['description' => $cat['description']]
            );
        }
    }
}
