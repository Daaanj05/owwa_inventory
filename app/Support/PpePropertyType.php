<?php

namespace App\Support;

/**
 * PPE account / type keys for Appendix 73 RPCPPE.
 * Distinct from ItemPropertyClass (semi-expendable property classes).
 */
class PpePropertyType
{
    public const Land = 'land';

    public const LandImprovements = 'land_improvements';

    public const InfrastructureAssets = 'infrastructure_assets';

    public const BuildingsOtherStructures = 'buildings_other_structures';

    public const MachineryEquipment = 'machinery_equipment';

    public const HeavyEquipment = 'heavy_equipment';

    public const TechnicalScientificEquipment = 'technical_scientific_equipment';

    public const OfficeEquipment = 'office_equipment';

    public const TransportationEquipment = 'transportation_equipment';

    public const MotorVehicle = 'motor_vehicle';

    public const FurnitureFixturesBooks = 'furniture_fixtures_books';

    public const OtherPpe = 'other_ppe';

    /**
     * Legacy semi-style keys that may still be stored after the PPE column split.
     *
     * @var array<string, string>
     */
    public const LegacyMap = [
        'information_technology' => self::TechnicalScientificEquipment,
        'ict' => self::TechnicalScientificEquipment,
        'furniture_fixtures' => self::FurnitureFixturesBooks,
        'furnitures_fixtures' => self::FurnitureFixturesBooks,
        'communication_equipment' => self::OfficeEquipment,
        'appliances' => self::OfficeEquipment,
        'medical_equipment' => self::TechnicalScientificEquipment,
        'vehicle_equipment' => self::MotorVehicle,
        'sports_equipment' => self::MachineryEquipment,
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Land => 'Land',
            self::LandImprovements => 'Land Improvements',
            self::InfrastructureAssets => 'Infrastructure Assets',
            self::BuildingsOtherStructures => 'Buildings and Other Structures',
            self::MachineryEquipment => 'Machinery and Equipment',
            self::HeavyEquipment => 'Heavy Equipment',
            self::TechnicalScientificEquipment => 'Technical and Scientific Equipment',
            self::OfficeEquipment => 'Office Equipment',
            self::TransportationEquipment => 'Transportation Equipment',
            self::MotorVehicle => 'Motor Vehicle',
            self::FurnitureFixturesBooks => 'Furniture, Fixtures and Books',
            self::OtherPpe => 'Other Property, Plant and Equipment',
        ];
    }

    public static function normalize(?string $ppeType): ?string
    {
        if (blank($ppeType)) {
            return null;
        }

        if (array_key_exists($ppeType, self::options())) {
            return $ppeType;
        }

        return self::LegacyMap[$ppeType] ?? null;
    }

    public static function resolveForExport(?string $ppeType): string
    {
        return self::normalize($ppeType) ?? self::OfficeEquipment;
    }

    public static function propertyTypeLabel(?string $ppeType): string
    {
        $resolved = self::resolveForExport($ppeType);

        return mb_strtoupper(self::options()[$resolved] ?? 'OFFICE EQUIPMENT');
    }

    public static function supplyTypeCode(?string $ppeType): string
    {
        $resolved = self::resolveForExport($ppeType);
        $code = config("inventory.ppe_class_codes.{$resolved}")
            ?? config("inventory.catalog_class_codes.{$resolved}");

        if (is_string($code) && $code !== '') {
            return strtoupper($code);
        }

        return 'OE';
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
            'land improvement' => self::LandImprovements,
            'infrastructure' => self::InfrastructureAssets,
            'building' => self::BuildingsOtherStructures,
            'heavy equipment' => self::HeavyEquipment,
            'technical' => self::TechnicalScientificEquipment,
            'scientific' => self::TechnicalScientificEquipment,
            'office equipment' => self::OfficeEquipment,
            'motor vehicle' => self::MotorVehicle,
            'vehicle' => self::MotorVehicle,
            'transport' => self::TransportationEquipment,
            'furniture' => self::FurnitureFixturesBooks,
            'fixture' => self::FurnitureFixturesBooks,
            'machinery' => self::MachineryEquipment,
            'information technology' => self::TechnicalScientificEquipment,
            'ict' => self::TechnicalScientificEquipment,
            'medical' => self::TechnicalScientificEquipment,
        ];

        foreach ($aliases as $needle => $ppeType) {
            if (str_contains($normalized, $needle)) {
                return $ppeType;
            }
        }

        return null;
    }
}
