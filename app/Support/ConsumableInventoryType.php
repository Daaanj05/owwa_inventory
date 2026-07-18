<?php

namespace App\Support;

class ConsumableInventoryType
{
    public const OfficeSupplies = 'office_supplies';

    public const AccountableForms = 'accountable_forms';

    public const MedicalDentalLaboratory = 'medical_dental_laboratory';

    public const FoodSupplies = 'food_supplies';

    public const OtherSupplies = 'other_supplies';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::OfficeSupplies => 'Office Supplies Inventory',
            self::AccountableForms => 'Accountable Forms Inventory',
            self::MedicalDentalLaboratory => 'Medical, Dental and Laboratory Supplies Inventory',
            self::FoodSupplies => 'Food Supplies Inventory',
            self::OtherSupplies => 'Other Supplies Inventory',
        ];
    }

    public static function normalize(?string $inventoryType): ?string
    {
        if (blank($inventoryType)) {
            return null;
        }

        return array_key_exists($inventoryType, self::options()) ? $inventoryType : null;
    }

    public static function label(?string $inventoryType): string
    {
        $normalized = self::normalize($inventoryType);

        if ($normalized === null) {
            return '';
        }

        return self::options()[$normalized];
    }

    public static function resolveForExport(?string $inventoryType): string
    {
        return self::normalize($inventoryType) ?? self::OfficeSupplies;
    }
}
