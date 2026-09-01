<?php

namespace App\Support;

use App\Models\Item;
use Illuminate\Support\Str;

class ConsumableInventoryType
{
    public const OfficeSupplies = 'office_supplies';

    public const AccountableForms = 'accountable_forms';

    public const MedicalDentalLaboratory = 'medical_dental_laboratory';

    public const FoodSupplies = 'food_supplies';

    public const JanitorialSupplies = 'janitorial_supplies';

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
            self::JanitorialSupplies => 'Janitorial Supplies Inventory',
            self::OtherSupplies => 'Other Supplies Inventory',
        ];
    }

    /**
     * Official labels plus types already saved on catalog items.
     *
     * @return list<string>
     */
    public static function suggestionLabels(): array
    {
        $labels = array_values(self::options());

        $used = Item::query()
            ->whereNotNull('inventory_type')
            ->where('inventory_type', '!=', '')
            ->distinct()
            ->orderBy('inventory_type')
            ->pluck('inventory_type')
            ->map(fn (string $type): string => self::label($type))
            ->filter(fn (string $label): bool => $label !== '')
            ->all();

        return array_values(array_unique(array_merge($labels, $used)));
    }

    /**
     * @return array<string, string>
     */
    public static function optionsWithUsed(): array
    {
        $options = self::options();

        foreach (Item::query()
            ->whereNotNull('inventory_type')
            ->where('inventory_type', '!=', '')
            ->distinct()
            ->orderBy('inventory_type')
            ->pluck('inventory_type') as $type
        ) {
            if (! array_key_exists($type, $options)) {
                $label = self::label($type);
                if ($label !== '') {
                    $options[$type] = $label;
                }
            }
        }

        return $options;
    }

    public static function normalize(?string $inventoryType): ?string
    {
        if (blank($inventoryType)) {
            return null;
        }

        if (array_key_exists($inventoryType, self::options())) {
            return $inventoryType;
        }

        return self::isStoredKey($inventoryType) ? $inventoryType : null;
    }

    public static function resolve(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $normalized = self::normalize($value);
        if ($normalized !== null) {
            return $normalized;
        }

        $needle = Str::of($value)
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        foreach (self::options() as $key => $label) {
            $labelNeedle = Str::of($label)
                ->lower()
                ->replace(' inventory', '')
                ->replace(' supplies', ' supplies')
                ->replaceMatches('/\s+/', ' ')
                ->trim()
                ->toString();

            if ($needle === $labelNeedle || str_contains($labelNeedle, $needle) || str_contains($needle, $labelNeedle)) {
                return $key;
            }
        }

        return match (true) {
            str_contains($needle, 'office') => self::OfficeSupplies,
            str_contains($needle, 'accountable') => self::AccountableForms,
            str_contains($needle, 'medical') || str_contains($needle, 'dental') || str_contains($needle, 'laboratory') => self::MedicalDentalLaboratory,
            str_contains($needle, 'food') => self::FoodSupplies,
            str_contains($needle, 'janitorial') => self::JanitorialSupplies,
            str_contains($needle, 'other') => self::OtherSupplies,
            default => self::customKeyFromLabel($value),
        };
    }

    public static function label(?string $inventoryType): string
    {
        $normalized = self::normalize($inventoryType);

        if ($normalized === null) {
            return '';
        }

        return self::options()[$normalized] ?? Str::of($normalized)
            ->replace('_', ' ')
            ->title()
            ->toString();
    }

    public static function resolveForExport(?string $inventoryType): string
    {
        return self::normalize($inventoryType) ?? self::OfficeSupplies;
    }

    public static function customKeyFromLabel(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $slug = Str::of($value)
            ->lower()
            ->replace('&', ' and ')
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->substr(0, 50)
            ->trim('_')
            ->toString();

        if ($slug === '' || ! self::isStoredKey($slug)) {
            return null;
        }

        return $slug;
    }

    protected static function isStoredKey(string $inventoryType): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{0,49}$/', $inventoryType);
    }
}
