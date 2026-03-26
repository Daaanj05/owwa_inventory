<?php

namespace Database\Seeders;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class InventoryScenarioSeeder extends Seeder
{
    /**
     * Seed semi‑expendable, PPE, and other items with acquisitions and
     * issuances that create realistic stock situations (some healthy,
     * some near reorder, some clearly low) so dashboards and the AI
     * recommendations have good demo data.
     */
    public function run(): void
    {
        // Ensure we have a main office and a few departments.
        $office = Office::firstOrCreate(
            ['code' => 'REG'],
            ['code' => 'REG', 'name' => 'Regional Office']
        );

        $departments = [
            'ADM' => 'Admin',
            'OPS' => 'Operations',
            'FIN' => 'Finance',
            'ITD' => 'IT / Digital',
        ];

        $deptModels = [];
        foreach ($departments as $code => $name) {
            $deptModels[$code] = Department::firstOrCreate(
                ['code' => $code],
                ['code' => $code, 'name' => $name, 'office_id' => $office->id]
            );
        }

        // Ensure base categories exist, including PPE / Safety.
        $consumablesCat = ItemCategory::firstOrCreate(
            ['name' => 'Consumables'],
            ['description' => 'Office consumables and supplies']
        );

        $semiCat = ItemCategory::firstOrCreate(
            ['name' => 'Semi-Expendable'],
            ['description' => 'Semi-expendable properties']
        );

        $ppeCat = ItemCategory::firstOrCreate(
            ['name' => 'PPE / Safety'],
            ['description' => 'Personal protective equipment and safety gear']
        );

        // Define demo items and target scenarios.
        $itemsConfig = [
            // Consumables – used almost every month.
            [
                'name'          => 'Bond Paper A4',
                'unit'          => 'ream',
                'category'      => $consumablesCat,
                'item_code'     => 'CONS-BOND-A4',
                'reorder_level' => 50,
                'initial_stock' => 300,
                'total_issuance'=> 260, // leaves 40 – below reorder (clearly low)
                'dept_code'     => 'ADM',
            ],
            [
                'name'          => 'Ballpen Blue',
                'unit'          => 'piece',
                'category'      => $consumablesCat,
                'item_code'     => 'CONS-BP-BLUE',
                'reorder_level' => 100,
                'initial_stock' => 600,
                'total_issuance'=> 350, // leaves 250 – healthy
                'dept_code'     => 'OPS',
            ],
            [
                'name'          => 'Tissue Paper',
                'unit'          => 'roll',
                'category'      => $consumablesCat,
                'item_code'     => 'CONS-TISSUE',
                'reorder_level' => 80,
                'initial_stock' => 400,
                'total_issuance'=> 330, // leaves 70 – slightly below reorder
                'dept_code'     => 'FIN',
            ],

            // Semi‑expendable – lower volumes, slower usage.
            [
                'name'          => 'Office Chair',
                'unit'          => 'piece',
                'category'      => $semiCat,
                'item_code'     => 'SEMI-CHAIR',
                'reorder_level' => 5,
                'initial_stock' => 20,
                'total_issuance'=> 10, // leaves 10 – above reorder
                'dept_code'     => 'ADM',
            ],
            [
                'name'          => 'Laser Printer',
                'unit'          => 'unit',
                'category'      => $semiCat,
                'item_code'     => 'SEMI-PRINTER',
                'reorder_level' => 2,
                'initial_stock' => 6,
                'total_issuance'=> 5, // leaves 1 – below reorder (critical)
                'dept_code'     => 'ITD',
            ],

            // PPE / Safety – important but issued less often.
            [
                'name'          => 'Safety Helmet',
                'unit'          => 'piece',
                'category'      => $ppeCat,
                'item_code'     => 'PPE-HELMET',
                'reorder_level' => 15,
                'initial_stock' => 40,
                'total_issuance'=> 30, // leaves 10 – below reorder
                'dept_code'     => 'OPS',
            ],
            [
                'name'          => 'Safety Shoes',
                'unit'          => 'pair',
                'category'      => $ppeCat,
                'item_code'     => 'PPE-SHOES',
                'reorder_level' => 10,
                'initial_stock' => 25,
                'total_issuance'=> 8, // leaves 17 – healthy
                'dept_code'     => 'OPS',
            ],
        ];

        $baseDate = Carbon::today()->subMonths(6)->startOfMonth();

        foreach ($itemsConfig as $config) {
            /** @var \App\Models\ItemCategory $cat */
            $cat = $config['category'];

            $item = Item::firstOrCreate(
                ['item_code' => $config['item_code']],
                [
                    'item_category_id' => $cat->id,
                    'name'             => $config['name'],
                    'unit'             => $config['unit'],
                    'item_code'        => $config['item_code'],
                    'value_type'       => 'low',
                    'reorder_level'    => $config['reorder_level'],
                    'description'      => 'Demo item for AI procurement scenarios',
                ]
            );

            // Single acquisition to set initial stock (idempotent on reference_code).
            Acquisition::firstOrCreate(
                ['reference_code' => 'ACQ-DEMO-' . $config['item_code']],
                [
                    'item_id'          => $item->id,
                    'office_id'        => $office->id,
                    'quantity'         => $config['initial_stock'],
                    'unit_cost'        => 100,
                    'acquisition_date' => $baseDate->copy(),
                    'source'           => 'Demo seed',
                    'remarks'          => 'Demo inventory scenario',
                    'recorded_by'      => null,
                ]
            );

            // Spread issuances across several months so averages and trends make sense.
            $remainingToIssue = $config['total_issuance'];
            $month = 0;
            while ($remainingToIssue > 0 && $month < 6) {
                $issueQty = min(
                    $remainingToIssue,
                    max(1, (int) floor($config['total_issuance'] / 6))
                );

                Issuance::firstOrCreate(
                    ['reference_code' => 'ISS-DEMO-' . $config['item_code'] . '-' . ($month + 1)],
                    [
                        'item_id'        => $item->id,
                        'office_id'      => $office->id,
                        'department_id'  => $deptModels[$config['dept_code']]->id,
                        'requisition_id' => null,
                        'quantity'       => $issueQty,
                        'issuance_date'  => $baseDate->copy()->addMonths($month),
                        'remarks'        => 'Demo issuance for AI procurement scenario',
                        'issued_by'      => null,
                        'issued_to'      => null,
                    ]
                );

                $remainingToIssue -= $issueQty;
                $month++;
            }
        }

        $this->command?->info('InventoryScenarioSeeder: demo semi‑expendable, PPE, and consumable items created with realistic stock levels.');
    }
}

