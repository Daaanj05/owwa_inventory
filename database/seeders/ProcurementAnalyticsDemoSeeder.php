<?php

namespace Database\Seeders;

use App\Models\Acquisition;
use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Support\DemoStockLedgerCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ProcurementAnalyticsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $office = Office::query()->firstWhere('code', DemoStockLedgerCatalog::REGIONAL_OFFICE);
        $consumables = ItemCategory::query()->firstWhere('name', 'Consumables');
        $custodian = User::query()->firstWhere('email', 'custodian@owwa.gov.ph');

        if ($office === null || $consumables === null || $custodian === null) {
            return;
        }

        $department = Department::query()
            ->where('office_id', $office->id)
            ->where('code', 'ADM')
            ->first()
            ?? Department::query()->where('office_id', $office->id)->first();

        if ($department === null) {
            return;
        }

        $this->seedSuggestedReorders($office, $consumables, $department, $custodian);
        $this->seedReplacementDue($office, $custodian);
    }

    protected function seedSuggestedReorders(
        Office $office,
        ItemCategory $consumables,
        Department $department,
        User $custodian,
    ): void {
        $specs = [
            [
                'item_code' => 'CON-PA-001',
                'base_name' => 'Bond Paper',
                'sub_item' => 'A4 (Forecast Demo)',
                'unit' => 'ream',
                'reorder_level' => 30,
                'monthly_qty' => 12,
                'stock_left' => 8,
                'unit_cost' => 185,
            ],
            [
                'item_code' => 'CON-PA-002',
                'base_name' => 'Ballpoint Pen',
                'sub_item' => 'Blue (Forecast Demo)',
                'unit' => 'piece',
                'reorder_level' => 15,
                'monthly_qty' => 8,
                'stock_left' => 16,
                'unit_cost' => 12,
            ],
            [
                'item_code' => 'CON-PA-003',
                'base_name' => 'Alcohol 70%',
                'sub_item' => '500ml (Forecast Demo)',
                'unit' => 'bottle',
                'reorder_level' => 10,
                'monthly_qty' => 6,
                'stock_left' => 4,
                'unit_cost' => 45,
            ],
        ];

        $requisition = Requisition::query()->updateOrCreate(
            ['reference_code' => 'REQ-PA-FORECAST'],
            [
                'office_id' => $office->id,
                'department_id' => $department->id,
                'requested_by' => $custodian->id,
                'status' => Requisition::STATUS_ACCEPTED,
                'remarks' => 'Demo issuances so suggested reorders show forecast and cover.',
                'approved_by' => $custodian->id,
                'approved_at' => now()->subMonths(6),
            ],
        );

        foreach ($specs as $spec) {
            $item = Item::query()->updateOrCreate(
                ['item_code' => $spec['item_code']],
                [
                    'item_category_id' => $consumables->id,
                    'base_name' => $spec['base_name'],
                    'sub_item' => $spec['sub_item'],
                    'name' => Item::mergeDisplayName($spec['base_name'], $spec['sub_item']),
                    'unit' => $spec['unit'],
                    'reorder_level' => $spec['reorder_level'],
                ],
            );

            RequisitionItem::query()->updateOrCreate(
                [
                    'requisition_id' => $requisition->id,
                    'item_id' => $item->id,
                ],
                ['quantity' => $spec['monthly_qty'] * 6],
            );

            $issued = 0;
            for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
                $issued += $spec['monthly_qty'];
                Issuance::query()->updateOrCreate(
                    ['reference_code' => 'ISS-PA-'.$spec['item_code'].'-M'.$monthsAgo],
                    [
                        'requisition_id' => $requisition->id,
                        'item_id' => $item->id,
                        'office_id' => $office->id,
                        'department_id' => $department->id,
                        'quantity' => $spec['monthly_qty'],
                        'unit_cost' => $spec['unit_cost'],
                        'amount' => $spec['unit_cost'] * $spec['monthly_qty'],
                        'issuance_date' => now()->subMonths($monthsAgo)->startOfMonth()->toDateString(),
                        'issued_by' => $custodian->id,
                        'remarks' => 'Forecast demo issuance.',
                    ],
                );
            }

            Acquisition::query()->updateOrCreate(
                ['reference_code' => 'ACQ-PA-'.$spec['item_code']],
                [
                    'item_id' => $item->id,
                    'office_id' => $office->id,
                    'quantity' => $issued + $spec['stock_left'],
                    'unit_cost' => $spec['unit_cost'],
                    'acquisition_date' => now()->subMonths(6)->toDateString(),
                    'source' => 'purchase',
                    'remarks' => 'Opening stock for the forecast demo.',
                    'recorded_by' => $custodian->id,
                ],
            );
        }
    }

    protected function seedReplacementDue(Office $office, User $custodian): void
    {
        $employees = User::query()
            ->whereIn('email', ['maria@owwa.gov.ph', 'juan@owwa.gov.ph', 'anna@owwa.gov.ph'])
            ->orderBy('email')
            ->get();

        if ($employees->isEmpty()) {
            $employees = collect([$custodian]);
        }

        $departments = Department::query()
            ->where('office_id', $office->id)
            ->whereIn('code', ['ADM', 'OPS', 'FIN'])
            ->get()
            ->keyBy('code');

        $fallbackDepartment = $departments->first()
            ?? Department::query()->where('office_id', $office->id)->first();

        if ($fallbackDepartment === null) {
            return;
        }

        $specs = [
            [
                'item_code' => 'SEM-001',
                'reference' => 'ISS-EUL-DEMO-SEM-001',
                'requisition' => 'REQ-EUL-DEMO-SEM-001',
                'property_number' => 'SPHV-EUL-DEMO-SEM-001',
                'months_ago' => 52,
                'department' => 'OPS',
                'condition' => null,
                'unit_cost' => 850,
            ],
            [
                'item_code' => 'SEM-002',
                'reference' => 'ISS-EUL-DEMO-SEM-002',
                'requisition' => 'REQ-EUL-DEMO-SEM-002',
                'property_number' => 'SPHV-EUL-DEMO-SEM-002',
                'months_ago' => 62,
                'department' => 'FIN',
                'condition' => null,
                'unit_cost' => 2400,
            ],
            [
                'item_code' => 'SEM-003',
                'reference' => 'ISS-EUL-DEMO-SEM-003',
                'requisition' => 'REQ-EUL-DEMO-SEM-003',
                'property_number' => 'SPHV-EUL-DEMO-SEM-003',
                'months_ago' => 50,
                'department' => 'ADM',
                'condition' => Issuance::USEFUL_LIFE_NEEDS_REPLACEMENT,
                'unit_cost' => 1500,
                'spare_stock' => 2,
            ],
        ];

        foreach ($specs as $index => $spec) {
            $item = Item::query()->firstWhere('item_code', $spec['item_code']);
            if ($item === null) {
                continue;
            }

            $employee = $employees[$index % $employees->count()];
            $department = $departments->get($spec['department']) ?? $fallbackDepartment;
            $issuedOn = Carbon::now()->subMonths($spec['months_ago'])->toDateString();

            $requisition = Requisition::query()
                ->where('reference_code', $spec['requisition'])
                ->orWhere('transaction_number', $spec['requisition'])
                ->first();

            $requisitionAttributes = [
                'office_id' => $office->id,
                'department_id' => $department->id,
                'requested_by' => $custodian->id,
                'status' => Requisition::STATUS_ACCEPTED,
                'remarks' => 'Demo issuance so Replacement due lists another semi-expendable unit.',
                'approved_by' => $custodian->id,
                'approved_at' => Carbon::parse($issuedOn),
                'reference_code' => $spec['requisition'],
            ];

            if ($requisition === null) {
                $requisition = Requisition::query()->create($requisitionAttributes);
            } else {
                $requisition->update($requisitionAttributes);
            }

            RequisitionItem::query()->updateOrCreate(
                [
                    'requisition_id' => $requisition->id,
                    'item_id' => $item->id,
                ],
                ['quantity' => 1],
            );

            Issuance::query()->updateOrCreate(
                ['reference_code' => $spec['reference']],
                [
                    'requisition_id' => $requisition->id,
                    'item_id' => $item->id,
                    'office_id' => $office->id,
                    'department_id' => $department->id,
                    'quantity' => 1,
                    'unit_cost' => $spec['unit_cost'],
                    'amount' => $spec['unit_cost'],
                    'issuance_date' => $issuedOn,
                    'estimated_useful_life' => '60 months',
                    'property_number' => $spec['property_number'],
                    'issued_by' => $custodian->id,
                    'issued_to' => $employee->id,
                    'useful_life_condition' => $spec['condition'],
                    'remarks' => 'Demo unit nearing or past useful life.',
                ],
            );

            if (($spec['spare_stock'] ?? 0) > 0) {
                Acquisition::query()->updateOrCreate(
                    ['reference_code' => 'ACQ-EUL-DEMO-'.$spec['item_code']],
                    [
                        'item_id' => $item->id,
                        'office_id' => $office->id,
                        'quantity' => (int) $spec['spare_stock'] + 1,
                        'unit_cost' => $spec['unit_cost'],
                        'acquisition_date' => Carbon::parse($issuedOn)->toDateString(),
                        'source' => 'purchase',
                        'remarks' => 'Spare stock so replacement can be issued from the shelf.',
                        'recorded_by' => $custodian->id,
                    ],
                );
            }
        }
    }
}
