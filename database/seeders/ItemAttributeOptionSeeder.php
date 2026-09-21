<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\ItemAttributeOption;
use App\Support\ConsumableInventoryType;
use App\Support\ItemMeasurementUnitInput;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use Illuminate\Database\Seeder;

class ItemAttributeOptionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['piece', 'ream', 'box'] as $unit) {
            $this->upsert(ItemAttributeOption::KIND_UNIT, $unit, $unit);
        }

        Item::query()
            ->whereNotNull('unit')
            ->where('unit', '!=', '')
            ->distinct()
            ->pluck('unit')
            ->each(function (string $unit): void {
                if (ItemMeasurementUnitInput::isValid($unit)) {
                    $this->upsert(ItemAttributeOption::KIND_UNIT, $unit, $unit);
                }
            });

        foreach (ConsumableInventoryType::options() as $value => $label) {
            $this->upsert(ItemAttributeOption::KIND_INVENTORY_TYPE, $value, $label);
        }

        foreach (ItemPropertyClass::options() as $value => $label) {
            $this->upsert(ItemAttributeOption::KIND_PROPERTY_CLASS, $value, $label);
        }

        foreach (PpePropertyType::options() as $value => $label) {
            $this->upsert(ItemAttributeOption::KIND_PPE_TYPE, $value, $label);
        }
    }

    private function upsert(string $kind, string $value, string $label): void
    {
        ItemAttributeOption::query()->firstOrCreate(
            ['kind' => $kind, 'value' => $value],
            ['label' => $label, 'is_active' => true],
        );
    }
}
