<?php

namespace App\Support;

class ItemPropertyClass
{
    public const InformationTechnology = 'information_technology';

    public const FurnitureFixtures = 'furniture_fixtures';

    public const OfficeEquipment = 'office_equipment';

    public const CommunicationEquipment = 'communication_equipment';

    public const Appliances = 'appliances';

    public const MachineryEquipment = 'machinery_equipment';

    public const TransportationEquipment = 'transportation_equipment';

    public const MedicalEquipment = 'medical_equipment';

    /** @deprecated Use InformationTechnology */
    public const Ict = self::InformationTechnology;

    /** @deprecated Use FurnitureFixtures */
    public const FurnituresFixtures = self::FurnitureFixtures;

    /** @deprecated Use MachineryEquipment */
    public const SportsEquipment = self::MachineryEquipment;

    /** @deprecated Use TransportationEquipment */
    public const VehicleEquipment = self::TransportationEquipment;

    /**
     * Legacy aliases mapped to current keys.
     *
     * @var array<string, string>
     */
    public const LegacyMap = [
        'ict' => self::InformationTechnology,
        'furnitures_fixtures' => self::FurnitureFixtures,
        'sports_equipment' => self::MachineryEquipment,
        'vehicle_equipment' => self::TransportationEquipment,
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::InformationTechnology => 'Information Technology Equipment',
            self::FurnitureFixtures => 'Furniture and Fixtures',
            self::OfficeEquipment => 'Office Equipment',
            self::CommunicationEquipment => 'Communication Equipment',
            self::Appliances => 'Appliances',
            self::MachineryEquipment => 'Machinery and Equipment',
            self::TransportationEquipment => 'Transportation Equipment',
            self::MedicalEquipment => 'Medical Equipment',
        ];
    }

    public static function normalize(?string $propertyClass): ?string
    {
        if (blank($propertyClass)) {
            return null;
        }

        if (array_key_exists($propertyClass, self::options())) {
            return $propertyClass;
        }

        return self::LegacyMap[$propertyClass] ?? null;
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

        return self::resolveFromInventoryTypeLabel($value);
    }

    public static function label(?string $propertyClass): ?string
    {
        if (blank($propertyClass)) {
            return null;
        }

        $normalized = self::normalize($propertyClass);

        return $normalized !== null ? (self::options()[$normalized] ?? null) : null;
    }

    public static function resolveForExport(?string $propertyClass): string
    {
        $normalized = self::normalize($propertyClass);

        if ($normalized !== null) {
            return $normalized;
        }

        return self::OfficeEquipment;
    }

    public static function propertyTypeLabel(?string $propertyClass): string
    {
        return match (self::resolveForExport($propertyClass)) {
            self::InformationTechnology => 'INFORMATION TECHNOLOGY EQUIPMENT',
            self::FurnitureFixtures => 'FURNITURE AND FIXTURES',
            self::OfficeEquipment => 'OFFICE EQUIPMENT',
            self::CommunicationEquipment => 'COMMUNICATION EQUIPMENT',
            self::Appliances => 'APPLIANCES',
            self::MachineryEquipment => 'MACHINERY AND EQUIPMENT',
            self::TransportationEquipment => 'TRANSPORTATION EQUIPMENT',
            self::MedicalEquipment => 'MEDICAL EQUIPMENT',
            default => 'OFFICE EQUIPMENT',
        };
    }

    public static function sheetNameForForm(string $formSlug, ?string $propertyClass): ?string
    {
        $resolved = self::resolveForExport($propertyClass);

        if (blank($propertyClass)) {
            $default = config("owwa_templates.property_class_sheets.default.{$formSlug}");

            return is_string($default) && $default !== '' ? $default : null;
        }

        $sheet = config("owwa_templates.property_class_sheets.forms.{$formSlug}.{$resolved}");

        if (is_string($sheet) && $sheet !== '') {
            return $sheet;
        }

        $generic = config("owwa_templates.property_class_sheets.generic.{$resolved}");

        return is_string($generic) && $generic !== '' ? $generic : null;
    }

    public static function supplyTypeCode(?string $propertyClass): string
    {
        $resolved = self::resolveForExport($propertyClass);
        $code = config("inventory.catalog_class_codes.{$resolved}");

        if (is_string($code) && $code !== '') {
            return strtoupper($code);
        }

        return 'OE';
    }

    /**
     * @deprecated Use item.uacsObjectCode->code; kept for legacy callers.
     */
    public static function uacsPrefix(?string $propertyClass): string
    {
        return '106';
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
            'information technology' => self::InformationTechnology,
            'ict' => self::InformationTechnology,
            'furniture' => self::FurnitureFixtures,
            'furnitures' => self::FurnitureFixtures,
            'fixtures' => self::FurnitureFixtures,
            'office' => self::OfficeEquipment,
            'communication' => self::CommunicationEquipment,
            'appliance' => self::Appliances,
            'machinery' => self::MachineryEquipment,
            'transport' => self::TransportationEquipment,
            'vehicle' => self::TransportationEquipment,
            'medical' => self::MedicalEquipment,
        ];

        foreach ($aliases as $needle => $propertyClass) {
            if (str_contains($normalized, $needle)) {
                return $propertyClass;
            }
        }

        return null;
    }
}
