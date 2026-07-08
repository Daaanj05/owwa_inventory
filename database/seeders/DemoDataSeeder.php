<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Support\DemoSemiItemCatalog;
use App\Support\SemiExpendableUsefulLife;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $regional = Office::query()->updateOrCreate(
            ['code' => 'OWWA-IVA'],
            [
                'name' => 'OWWA Regional Office IV-A',
                'fund_cluster' => '01',
                'is_satellite' => false,
                'is_regional_supply' => true,
                'address' => 'CALABARZON',
            ],
        );

        $satellite = Office::query()->updateOrCreate(
            ['code' => 'OWWA-LAG'],
            [
                'name' => 'OWWA Satellite Office — Laguna',
                'fund_cluster' => '01',
                'is_satellite' => true,
                'is_regional_supply' => false,
                'address' => 'Sta. Cruz, Laguna',
            ],
        );

        $admin = Department::firstOrCreate(
            ['office_id' => $regional->id, 'name' => 'Administrative Division'],
            ['code' => 'ADM'],
        );
        $ops = Department::firstOrCreate(
            ['office_id' => $regional->id, 'name' => 'Operations Division'],
            ['code' => 'OPS'],
        );
        $finance = Department::firstOrCreate(
            ['office_id' => $regional->id, 'name' => 'Finance Division'],
            ['code' => 'FIN'],
        );
        $welfare = Department::firstOrCreate(
            ['office_id' => $satellite->id, 'name' => 'Welfare Services Unit'],
            ['code' => 'WSU'],
        );

        $sc = User::where('email', 'custodian@owwa.gov.ph')->firstOrFail();
        $sc->update(['office_id' => $regional->id, 'department_id' => $admin->id]);

        $uc = User::where('email', 'authorized@owwa.gov.ph')->firstOrFail();
        $uc->update(['office_id' => $regional->id, 'department_id' => $ops->id]);
        $uc->syncOfficeAssignments([
            ['office_id' => $regional->id, 'department_id' => $ops->id],
        ]);

        $sysAdmin = User::where('email', 'admin@owwa.gov.ph')->first();
        $sysAdmin?->update(['office_id' => $regional->id]);

        User::updateOrCreate(
            ['email' => 'maria@owwa.gov.ph'],
            [
                'name' => 'Maria Santos',
                'password' => 'password',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $regional->id,
                'department_id' => $ops->id,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'juan@owwa.gov.ph'],
            [
                'name' => 'Juan Dela Cruz',
                'password' => 'password',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $regional->id,
                'department_id' => $ops->id,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'anna@owwa.gov.ph'],
            [
                'name' => 'Anna Reyes',
                'password' => 'password',
                'role' => User::ROLE_EMPLOYEE,
                'office_id' => $regional->id,
                'department_id' => $finance->id,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'consolidator2@owwa.gov.ph'],
            [
                'name' => 'Roberto Cruz',
                'password' => 'password',
                'role' => User::ROLE_UNIT_CONSOLIDATOR,
                'office_id' => $satellite->id,
                'department_id' => $welfare->id,
                'email_verified_at' => now(),
            ]
        )->syncOfficeAssignments([
            ['office_id' => $satellite->id, 'department_id' => $welfare->id],
        ]);

        $consumables = ItemCategory::firstOrCreate(
            ['name' => 'Consumables'],
            ['description' => 'Office consumables and supplies'],
        );
        $semiExpendable = ItemCategory::firstOrCreate(
            ['name' => 'Semi-Expendable'],
            ['description' => 'Semi-expendable properties'],
        );
        $ppe = ItemCategory::firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'Property, plant and equipment (PPE)'],
        );

        $consumableItems = [
            ['name' => 'Bond Paper A4 (Ream)', 'unit' => 'ream', 'item_code' => 'CON-001', 'reorder_level' => 20],
            ['name' => 'Ballpoint Pen (Blue)', 'unit' => 'piece', 'item_code' => 'CON-002', 'reorder_level' => 50],
            ['name' => 'Ink Cartridge (Black)', 'unit' => 'piece', 'item_code' => 'CON-003', 'reorder_level' => 10],
            ['name' => 'Folder (Long)', 'unit' => 'piece', 'item_code' => 'CON-004', 'reorder_level' => 30],
            ['name' => 'Staple Wire No. 35', 'unit' => 'box', 'item_code' => 'CON-005', 'reorder_level' => 15],
            ['name' => 'Alcohol 70% (500ml)', 'unit' => 'bottle', 'item_code' => 'CON-006', 'reorder_level' => 25],
            ['name' => 'Tissue Paper (Roll)', 'unit' => 'roll', 'item_code' => 'CON-007', 'reorder_level' => 40],
            ['name' => 'Correction Tape', 'unit' => 'piece', 'item_code' => 'CON-008', 'reorder_level' => 20],
        ];

        foreach ($consumableItems as $ci) {
            Item::firstOrCreate(
                ['item_code' => $ci['item_code']],
                [
                    'item_category_id' => $consumables->id,
                    'name' => $ci['name'],
                    'unit' => $ci['unit'],
                    'reorder_level' => $ci['reorder_level'],
                ],
            );
        }

        $semiItems = [
            ['name' => 'Heavy-Duty Stapler', 'unit' => 'piece', 'item_code' => 'SEM-001', 'reorder_level' => 5],
            ['name' => 'Paper Cutter', 'unit' => 'piece', 'item_code' => 'SEM-002', 'reorder_level' => 3],
            ['name' => 'Desk Organizer', 'unit' => 'piece', 'item_code' => 'SEM-003', 'reorder_level' => 5],
            ['name' => 'Wall Clock', 'unit' => 'piece', 'item_code' => 'SEM-004', 'reorder_level' => 2],
            ['name' => 'Whiteboard (4x3 ft)', 'unit' => 'piece', 'item_code' => 'SEM-005', 'reorder_level' => 2],
        ];

        foreach ($semiItems as $si) {
            $attrs = DemoSemiItemCatalog::coreItems()[$si['item_code']] ?? [];
            Item::updateOrCreate(
                ['item_code' => $si['item_code']],
                [
                    'item_category_id' => $semiExpendable->id,
                    'name' => $si['name'],
                    'unit' => $si['unit'],
                    'value_type' => 'low',
                    'reorder_level' => $si['reorder_level'],
                    'property_class' => $attrs['property_class'] ?? null,
                    'estimated_useful_life' => $attrs['estimated_useful_life']
                        ?? SemiExpendableUsefulLife::defaultForPropertyClass($attrs['property_class'] ?? null),
                ],
            );
        }

        $ppeItems = [
            ['name' => 'Laptop (ThinkPad L14)', 'unit' => 'unit', 'item_code' => 'PPE-001', 'reorder_level' => 2],
            ['name' => 'Office Desk', 'unit' => 'unit', 'item_code' => 'PPE-002', 'reorder_level' => 2],
            ['name' => 'Printer (Laser)', 'unit' => 'unit', 'item_code' => 'PPE-003', 'reorder_level' => 1],
            ['name' => 'Air Conditioning Unit', 'unit' => 'unit', 'item_code' => 'PPE-004', 'reorder_level' => 1],
        ];

        foreach ($ppeItems as $pi) {
            Item::firstOrCreate(
                ['item_code' => $pi['item_code']],
                [
                    'item_category_id' => $ppe->id,
                    'name' => $pi['name'],
                    'unit' => $pi['unit'],
                    'reorder_level' => $pi['reorder_level'],
                ],
            );
        }
    }
}
