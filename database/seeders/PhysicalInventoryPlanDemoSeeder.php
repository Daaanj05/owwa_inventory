<?php

namespace Database\Seeders;

use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\PhysicalInventoryPlan;
use App\Models\PhysicalInventoryPlanLine;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PhysicalInventoryPlanDemoSeeder extends Seeder
{
    public function run(): void
    {
        $office = Office::query()->firstWhere('code', 'OWWA-IVA');
        $custodian = User::query()->where('email', 'custodian@owwa.gov.ph')->first();

        if (! $office || ! $custodian) {
            return;
        }

        $consumables = ItemCategory::query()->where('name', 'Consumables')->first();
        $semi = ItemCategory::query()->where('name', 'Semi-Expendable')->first();
        $ppe = ItemCategory::query()->where('name', 'Property, Plant and Equipment')->first();

        $rpci = PhysicalCountSession::query()->where('reference_code', 'PC-DEMO-RPCI-2026')->first()
            ?? PhysicalCountSession::query()->where('reference_code', 'PC-EXPORT-RPCI-2026')->first();
        $rpcsp = PhysicalCountSession::query()->where('reference_code', 'PC-DEMO-RPCSP-2026')->first()
            ?? PhysicalCountSession::query()->where('reference_code', 'PC-EXPORT-RPCSP-2026')->first();
        $rpcppe = PhysicalCountSession::query()->where('reference_code', 'PC-DEMO-RPCPPE-2026')->first()
            ?? PhysicalCountSession::query()->where('reference_code', 'PC-EXPORT-RPCPPE-2026')->first();

        if (! $rpci || ! $rpcsp || ! $rpcppe || ! $consumables || ! $semi || ! $ppe) {
            return;
        }

        $plan = PhysicalInventoryPlan::query()->updateOrCreate(
            ['reference_code' => 'IP-DEMO-FY2026'],
            [
                'title' => 'FY 2026 Physical Inventory — Demo',
                'period_label' => 'FY 2026',
                'cut_off_date' => Carbon::parse('2026-06-30'),
                'status' => PhysicalInventoryPlan::STATUS_IN_PROGRESS,
                'item_category_id' => null,
                'committee_chair_name' => 'Roberto Cruz',
                'property_officer_name' => 'Marita C. Ablis',
                'accounting_officer_name' => 'Anna Reyes',
                'approved_at' => Carbon::parse('2026-06-01'),
                'recorded_by' => $custodian->id,
            ],
        );

        $lines = [
            ['category' => $consumables, 'session' => $rpci, 'date' => '2026-06-15'],
            ['category' => $semi, 'session' => $rpcsp, 'date' => '2026-06-20'],
            ['category' => $ppe, 'session' => $rpcppe, 'date' => '2026-06-25'],
        ];

        foreach ($lines as $line) {
            PhysicalInventoryPlanLine::query()->updateOrCreate(
                [
                    'physical_inventory_plan_id' => $plan->id,
                    'office_id' => $office->id,
                    'item_category_id' => $line['category']->id,
                ],
                [
                    'planned_date' => Carbon::parse($line['date']),
                    'physical_count_session_id' => $line['session']->id,
                ],
            );
        }
    }
}
