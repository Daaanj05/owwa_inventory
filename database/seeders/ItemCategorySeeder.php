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

        $canonical = ItemCategory::query()
            ->where('name', 'Property, Plant and Equipment')
            ->first();

        if ($canonical === null) {
            return;
        }

        $legacyIds = ItemCategory::query()
            ->whereIn('name', ['PPE', 'Power Plant Equipment'])
            ->pluck('id');

        if ($legacyIds->isEmpty()) {
            return;
        }

        \App\Models\Item::query()
            ->whereIn('item_category_id', $legacyIds)
            ->update(['item_category_id' => $canonical->id]);

        ItemCategory::query()
            ->whereIn('id', $legacyIds)
            ->update(['archived_at' => now()]);
    }
}
