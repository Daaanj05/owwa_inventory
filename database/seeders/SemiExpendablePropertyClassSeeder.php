<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Support\DemoSemiItemCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SemiExpendablePropertyClassSeeder extends Seeder
{
    public function run(): void
    {
        $category = ItemCategory::firstOrCreate(
            ['name' => 'Semi-Expendable'],
            ['description' => 'Semi-expendable properties'],
        );

        $office = Office::query()->firstWhere('code', 'OWWA-IVA')
            ?? Office::factory()->create([
                'code' => 'OWWA-IVA',
                'name' => 'OWWA Regional Office IV-A',
                'fund_cluster' => '01',
            ]);

        $department = Department::query()
            ->where('office_id', $office->id)
            ->where('code', 'ADM')
            ->first()
            ?? Department::query()->firstOrCreate(
                ['office_id' => $office->id, 'name' => 'Administrative Division'],
                ['code' => 'ADM'],
            );

        $custodian = User::query()->where('email', 'custodian@owwa.gov.ph')->first()
            ?? User::query()->where('role', User::ROLE_SUPPLY_CUSTODIAN)
                ->where('office_id', $office->id)
                ->first();

        if (! $custodian) {
            return;
        }

        $requisition = Requisition::updateOrCreate(
            ['reference_code' => 'REQ-DEMO-SEM-CATALOG'],
            [
                'office_id' => $office->id,
                'department_id' => $department->id,
                'requested_by' => $custodian->id,
                'status' => Requisition::STATUS_ACCEPTED,
                'approved_by' => $custodian->id,
                'approved_at' => Carbon::parse('2026-05-18'),
                'remarks' => 'Showcase semi items — one per property class',
            ],
        );

        foreach (DemoSemiItemCatalog::catalogItems() as $spec) {
            $item = Item::updateOrCreate(
                ['item_code' => $spec['code']],
                [
                    'item_category_id' => $category->id,
                    'name' => $spec['name'],
                    'unit' => 'piece',
                    'property_class' => $spec['property_class'],
                    'estimated_useful_life' => $spec['estimated_useful_life'],
                    'value_type' => 'low',
                    'reorder_level' => 1,
                ],
            );

            RequisitionItem::updateOrCreate(
                [
                    'requisition_id' => $requisition->id,
                    'item_id' => $item->id,
                ],
                ['quantity' => 1],
            );
        }
    }
}
