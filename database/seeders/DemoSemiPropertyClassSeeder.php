<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Support\DemoSemiItemCatalog;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Database\Seeder;

class DemoSemiPropertyClassSeeder extends Seeder
{
    public function run(): void
    {
        $category = ItemCategory::query()->where('name', 'Semi-Expendable')->first();

        if (! $category) {
            return;
        }

        Item::query()
            ->where('item_category_id', $category->id)
            ->whereNull('property_class')
            ->each(function (Item $item): void {
                $propertyClass = DemoSemiItemCatalog::propertyClassForItemCode($item->item_code)
                    ?? \App\Support\ItemPropertyClass::OfficeEquipment;

                $updates = ['property_class' => $propertyClass];

                if (blank($item->estimated_useful_life)) {
                    $updates['estimated_useful_life'] = SemiExpendableUsefulLife::defaultForPropertyClass($propertyClass);
                }

                $item->update($updates);
            });
    }
}
