<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Support\ItemPropertyClass;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        $baseName = fake()->words(2, true);
        $subItem = fake()->optional(0.4)->word();

        return [
            'item_category_id' => ItemCategory::factory(),
            'base_name' => $baseName,
            'sub_item' => $subItem,
            'name' => Item::mergeDisplayName($baseName, $subItem),
            'unit' => fake()->randomElement(['piece', 'box', 'ream']),
            'item_code' => 'ITM-'.fake()->unique()->numberBetween(1000, 9999),
            'value_type' => fake()->randomElement(['low', 'high']),
            'reorder_level' => fake()->numberBetween(0, 20),
            'description' => fake()->optional(0.5)->sentence(),
        ];
    }

    public function ict(): static
    {
        return $this->state(fn (): array => ['property_class' => ItemPropertyClass::Ict]);
    }

    public function officeEquipment(): static
    {
        return $this->state(fn (): array => ['property_class' => ItemPropertyClass::OfficeEquipment]);
    }

    public function ppeOfficeEquipment(): static
    {
        return $this->state(fn (): array => [
            'ppe_type' => \App\Support\PpePropertyType::OfficeEquipment,
            'property_class' => null,
        ]);
    }

    public function officeSuppliesInventory(): static
    {
        return $this->state(fn (): array => [
            'inventory_type' => \App\Support\ConsumableInventoryType::OfficeSupplies,
            'property_class' => null,
            'ppe_type' => null,
        ]);
    }

    public function furnituresFixtures(): static
    {
        return $this->state(fn (): array => ['property_class' => ItemPropertyClass::FurnituresFixtures]);
    }

    public function sportsEquipment(): static
    {
        return $this->state(fn (): array => ['property_class' => ItemPropertyClass::SportsEquipment]);
    }

    public function medicalEquipment(): static
    {
        return $this->state(fn (): array => ['property_class' => ItemPropertyClass::MedicalEquipment]);
    }

    public function vehicleEquipment(): static
    {
        return $this->state(fn (): array => ['property_class' => ItemPropertyClass::VehicleEquipment]);
    }
}
