<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ConsumptionDemoSeeder extends Seeder
{
    /**
     * Creates sample offices, departments, and issuances so the dashboard
     * consumption trend has data. Safe to run multiple times (adds more demo data).
     */
    public function run(): void
    {
        if (Issuance::count() > 0) {
            $this->command->info('Issuances already exist. Add more via Inventory → Issuances to see more consumption trend data.');

            return;
        }

        // Demo office – satisfies required "code" column.
        $office = Office::firstOrCreate(
            ['code' => 'REG'],
            ['code' => 'REG', 'name' => 'Regional Office']
        );

        // Demo departments – satisfy required "code" and "office_id" columns.
        $departments = [
            'ADM' => 'Admin',
            'OPS' => 'Operations',
            'FIN' => 'Finance',
            'ITD' => 'IT / Digital',
        ];

        $deptModels = [];
        foreach ($departments as $code => $name) {
            $deptModels[] = Department::firstOrCreate(
                ['code' => $code],
                ['code' => $code, 'name' => $name, 'office_id' => $office->id]
            );
        }

        // Use up to the first 3 items to make charts richer.
        $items = Item::take(3)->get();
        if ($items->isEmpty()) {
            $this->command->warn('No items in database. Add at least one item in Setup → Items, then run this seeder again.');

            return;
        }

        $baseDate = Carbon::today()->subMonths(3);
        $issuances = [];
        foreach ($items as $item) {
            foreach (range(0, 7) as $i) {
                $date = $baseDate->copy()->addWeeks($i);
                $dept = $deptModels[$i % count($deptModels)];
                $issuances[] = [
                    'item_id' => $item->id,
                    'office_id' => $office->id,
                    'department_id' => $dept->id,
                    'quantity' => rand(2, 15),
                    'issuance_date' => $date,
                ];
            }
        }

        foreach ($issuances as $payload) {
            Issuance::create(array_merge($payload, [
                'remarks' => 'Demo issuance for consumption trend',
            ]));
        }

        $this->command->info('Consumption demo data created. Refresh the dashboard to see consumption trends.');
    }
}
