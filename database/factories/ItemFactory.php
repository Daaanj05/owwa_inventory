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
        return [
            'item_category_id' => ItemCategory::factory(),
            'name' => fake()->word(),
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
