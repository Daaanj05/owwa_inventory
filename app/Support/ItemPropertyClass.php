<?php

namespace App\Support;

class ItemPropertyClass
{
    public const Ict = 'ict';

    public const OfficeEquipment = 'office_equipment';

    public const FurnituresFixtures = 'furnitures_fixtures';

    public const SportsEquipment = 'sports_equipment';

    public const MedicalEquipment = 'medical_equipment';

    public const VehicleEquipment = 'vehicle_equipment';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Ict => 'ICT',
            self::OfficeEquipment => 'Office equipment',
            self::FurnituresFixtures => 'Furnitures & fixtures',
            self::SportsEquipment => 'Sports equipment',
            self::MedicalEquipment => 'Medical equipment',
            self::VehicleEquipment => 'Vehicle equipment',
        ];
    }

    public static function resolveForExport(?string $propertyClass): string
    {
        if (filled($propertyClass) && array_key_exists($propertyClass, self::options())) {
            return $propertyClass;
        }

        return self::OfficeEquipment;
    }

    public static function propertyTypeLabel(?string $propertyClass): string
    {
        return match ($propertyClass) {
            self::Ict => 'INFORMATION & COMMUNICATION TECHNOLOGY',
            self::OfficeEquipment => 'OFFICE EQUIPMENT',
            self::FurnituresFixtures => 'FURNITURES & FIXTURES',
            self::SportsEquipment => 'SPORTS EQUIPMENT',
            self::MedicalEquipment => 'MEDICAL EQUIPMENT',
            self::VehicleEquipment => 'VEHICLE EQUIPMENT',
            default => 'OFFICE EQUIPMENT',
        };
    }

    public static function sheetNameForForm(string $formSlug, ?string $propertyClass): ?string
    {
        if (blank($propertyClass)) {
            $default = config("owwa_templates.property_class_sheets.default.{$formSlug}");

            return is_string($default) && $default !== '' ? $default : null;
        }

        $sheet = config("owwa_templates.property_class_sheets.forms.{$formSlug}.{$propertyClass}");

        if (is_string($sheet) && $sheet !== '') {
            return $sheet;
        }

        $generic = config("owwa_templates.property_class_sheets.generic.{$propertyClass}");

        return is_string($generic) && $generic !== '' ? $generic : null;
    }

    public static function supplyTypeCode(?string $propertyClass): string
    {
        $resolved = self::resolveForExport($propertyClass);
        $code = config("inventory.semi_supply_type_codes.{$resolved}");

        if (is_string($code) && $code !== '') {
            return strtoupper($code);
        }

        return 'OE';
    }

    public static function uacsPrefix(?string $propertyClass): string
    {
        $resolved = self::resolveForExport($propertyClass);
        $prefix = config("inventory.semi_uacs_prefixes.{$resolved}");

        return is_string($prefix) && $prefix !== '' ? $prefix : '106';
    }

    public static function resolveFromInventoryTypeLabel(string $label): ?string
    {
        $normalized = mb_strtolower(trim($label));

        foreach (self::options() as $key => $display) {
            if (str_contains($normalized, mb_strtolower($display))) {
                return $key;
            }
        }

        $aliases = [
            'office supplies' => self::OfficeEquipment,
            'furniture' => self::FurnituresFixtures,
            'furnitures' => self::FurnituresFixtures,
            'fixtures' => self::FurnituresFixtures,
            'medical' => self::MedicalEquipment,
            'sports' => self::SportsEquipment,
            'vehicle' => self::VehicleEquipment,
            'ict' => self::Ict,
        ];

        foreach ($aliases as $needle => $propertyClass) {
            if (str_contains($normalized, $needle)) {
                return $propertyClass;
            }
        }

        return null;
    }
}
