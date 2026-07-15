<?php

namespace Database\Seeders;

use App\Models\UacsObjectCode;
use App\Support\ItemPropertyClass;
use Illuminate\Database\Seeder;

class UacsObjectCodeSeeder extends Seeder
{
    public function run(): void
    {
        $starters = [
            ['code' => '106', 'name' => 'Property, Plant and Equipment (placeholder UACS)', 'property_class' => null],
            ['code' => '106-01', 'name' => 'Information Technology Equipment (placeholder)', 'property_class' => ItemPropertyClass::InformationTechnology],
            ['code' => '106-02', 'name' => 'Furniture and Fixtures (placeholder)', 'property_class' => ItemPropertyClass::FurnitureFixtures],
            ['code' => '106-03', 'name' => 'Office Equipment (placeholder)', 'property_class' => ItemPropertyClass::OfficeEquipment],
            ['code' => '106-04', 'name' => 'Communication Equipment (placeholder)', 'property_class' => ItemPropertyClass::CommunicationEquipment],
            ['code' => '106-05', 'name' => 'Appliances (placeholder)', 'property_class' => ItemPropertyClass::Appliances],
            ['code' => '106-06', 'name' => 'Machinery and Equipment (placeholder)', 'property_class' => ItemPropertyClass::MachineryEquipment],
            ['code' => '106-07', 'name' => 'Transportation Equipment (placeholder)', 'property_class' => ItemPropertyClass::TransportationEquipment],
            ['code' => '106-08', 'name' => 'Medical Equipment (placeholder)', 'property_class' => ItemPropertyClass::MedicalEquipment],
        ];

        foreach ($starters as $row) {
            UacsObjectCode::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'property_class' => $row['property_class'],
                    'is_active' => true,
                ],
            );
        }
    }
}
