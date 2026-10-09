<?php

use App\Models\ItemAttributeOption;
use App\Support\ConsumableInventoryType;
use App\Support\ItemPropertyClass;
use App\Support\PpePropertyType;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (ConsumableInventoryType::options() as $value => $label) {
            $this->insertMissing(ItemAttributeOption::KIND_INVENTORY_TYPE, $value, $label);
        }

        foreach (ItemPropertyClass::options() as $value => $label) {
            $this->insertMissing(ItemAttributeOption::KIND_PROPERTY_CLASS, $value, $label);
        }

        foreach (PpePropertyType::options() as $value => $label) {
            $this->insertMissing(ItemAttributeOption::KIND_PPE_TYPE, $value, $label);
        }
    }

    public function down(): void
    {
        // Official rows may already have been used by items. Leave them in place.
    }

    private function insertMissing(string $kind, string $value, string $label): void
    {
        ItemAttributeOption::query()->firstOrCreate(
            ['kind' => $kind, 'value' => $value],
            ['label' => $label, 'is_active' => true],
        );
    }
};
