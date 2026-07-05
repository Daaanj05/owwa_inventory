<?php

namespace App\Support;

class DemoSemiItemCatalog
{
    /**
     * Core demo semi items (SEM-001..005).
     *
     * @return array<string, array{property_class: string, estimated_useful_life: string|null}>
     */
    public static function coreItems(): array
    {
        return [
            'SEM-001' => [
                'property_class' => ItemPropertyClass::OfficeEquipment,
                'estimated_useful_life' => SemiExpendableUsefulLife::defaultForPropertyClass(ItemPropertyClass::OfficeEquipment),
            ],
            'SEM-002' => [
                'property_class' => ItemPropertyClass::OfficeEquipment,
                'estimated_useful_life' => SemiExpendableUsefulLife::defaultForPropertyClass(ItemPropertyClass::OfficeEquipment),
            ],
            'SEM-003' => [
                'property_class' => ItemPropertyClass::FurnituresFixtures,
                'estimated_useful_life' => SemiExpendableUsefulLife::defaultForPropertyClass(ItemPropertyClass::FurnituresFixtures),
            ],
            'SEM-004' => [
                'property_class' => ItemPropertyClass::OfficeEquipment,
                'estimated_useful_life' => SemiExpendableUsefulLife::defaultForPropertyClass(ItemPropertyClass::OfficeEquipment),
            ],
            'SEM-005' => [
                'property_class' => ItemPropertyClass::FurnituresFixtures,
                'estimated_useful_life' => SemiExpendableUsefulLife::defaultForPropertyClass(ItemPropertyClass::FurnituresFixtures),
            ],
        ];
    }

    /**
     * One showcase item per OWWA property class tab.
     *
     * @return array<string, array{code: string, name: string, property_class: string, estimated_useful_life: string|null}>
     */
    public static function catalogItems(): array
    {
        $classes = [
            ItemPropertyClass::Ict => ['code' => 'SEM-ICT-001', 'name' => 'Router — ICT'],
            ItemPropertyClass::OfficeEquipment => ['code' => 'SEM-OE-001', 'name' => 'Printer — Office equipment'],
            ItemPropertyClass::FurnituresFixtures => ['code' => 'SEM-FF-001', 'name' => 'Office chair — Furnitures & fixtures'],
            ItemPropertyClass::SportsEquipment => ['code' => 'SEM-SP-001', 'name' => 'Basketball — Sports equipment'],
            ItemPropertyClass::MedicalEquipment => ['code' => 'SEM-MD-001', 'name' => 'Wheelchair — Medical equipment'],
            ItemPropertyClass::VehicleEquipment => ['code' => 'SEM-VE-001', 'name' => 'Service van tools — Vehicle equipment'],
        ];

        $catalog = [];

        foreach ($classes as $propertyClass => $spec) {
            $catalog[$spec['code']] = [
                'code' => $spec['code'],
                'name' => $spec['name'],
                'property_class' => $propertyClass,
                'estimated_useful_life' => SemiExpendableUsefulLife::defaultForPropertyClass($propertyClass),
            ];
        }

        return $catalog;
    }

    /**
     * @return array<string, array{property_class: string, estimated_useful_life: string|null}>
     */
    public static function allItemAttributes(): array
    {
        $merged = self::coreItems();

        foreach (self::catalogItems() as $code => $spec) {
            $merged[$code] = [
                'property_class' => $spec['property_class'],
                'estimated_useful_life' => $spec['estimated_useful_life'],
            ];
        }

        return $merged;
    }

    public static function propertyClassForItemCode(string $itemCode): ?string
    {
        return self::allItemAttributes()[$itemCode]['property_class'] ?? null;
    }
}
