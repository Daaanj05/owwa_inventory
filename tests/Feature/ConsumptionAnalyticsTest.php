<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Services\ConsumptionAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConsumptionAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumption_includes_regional_and_satellite_offices_by_default(): void
    {
        $regional = Office::factory()->create(['name' => 'Regional Office', 'is_satellite' => false]);
        $satellite = Office::factory()->create(['name' => 'Satellite Office', 'is_satellite' => true]);
        $regionalDept = Department::query()->create([
            'office_id' => $regional->id,
            'name' => 'Regional Admin',
            'code' => 'RA',
        ]);
        $satelliteDept = Department::query()->create([
            'office_id' => $satellite->id,
            'name' => 'Satellite Admin',
            'code' => 'SA',
        ]);
        $item = Item::factory()->create();

        $from = Carbon::parse('2026-01-01');
        $to = Carbon::parse('2026-03-31');

        Issuance::withoutEvents(function () use ($item, $regional, $satellite, $regionalDept, $satelliteDept, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-REG-1',
                'item_id' => $item->id,
                'office_id' => $regional->id,
                'department_id' => $regionalDept->id,
                'quantity' => 10,
                'issuance_date' => $from->copy()->addDays(5),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-SAT-1',
                'item_id' => $item->id,
                'office_id' => $satellite->id,
                'department_id' => $satelliteDept->id,
                'quantity' => 5,
                'issuance_date' => $from->copy()->addDays(10),
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $totals = $service->getConsumptionTotalsByDepartment($from, $to);

        $this->assertSame(15, $totals['total']);
        $this->assertContains('Regional Admin', $totals['labels']);
        $this->assertContains('Satellite Admin', $totals['labels']);
    }

    public function test_consumption_can_filter_to_satellite_office_only(): void
    {
        $regional = Office::factory()->create(['is_satellite' => false]);
        $satellite = Office::factory()->create(['is_satellite' => true]);
        $regionalDept = Department::query()->create([
            'office_id' => $regional->id,
            'name' => 'Regional Dept',
            'code' => 'RD',
        ]);
        $satelliteDept = Department::query()->create([
            'office_id' => $satellite->id,
            'name' => 'Satellite Dept',
            'code' => 'SD',
        ]);
        $item = Item::factory()->create();

        $from = Carbon::parse('2026-02-01');
        $to = Carbon::parse('2026-02-28');

        Issuance::withoutEvents(function () use ($item, $regional, $satellite, $regionalDept, $satelliteDept, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-REG-2',
                'item_id' => $item->id,
                'office_id' => $regional->id,
                'department_id' => $regionalDept->id,
                'quantity' => 20,
                'issuance_date' => $from->copy()->addDay(),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-SAT-2',
                'item_id' => $item->id,
                'office_id' => $satellite->id,
                'department_id' => $satelliteDept->id,
                'quantity' => 7,
                'issuance_date' => $from->copy()->addDays(2),
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $totals = $service->getConsumptionTotalsByDepartment($from, $to, officeIds: [$satellite->id]);

        $this->assertSame(7, $totals['total']);
        $this->assertSame(['Satellite Dept'], $totals['labels']);
    }

    public function test_consumption_excludes_issuances_without_department(): void
    {
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'With Dept',
            'code' => 'WD',
        ]);
        $item = Item::factory()->create();

        $from = Carbon::parse('2026-03-01');
        $to = Carbon::parse('2026-03-31');

        Issuance::withoutEvents(function () use ($item, $office, $department, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-NODEPT',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => null,
                'quantity' => 100,
                'issuance_date' => $from->copy()->addDay(),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-DEPT',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => $department->id,
                'quantity' => 3,
                'issuance_date' => $from->copy()->addDays(2),
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $totals = $service->getConsumptionTotalsByDepartment($from, $to);

        $this->assertSame(3, $totals['total']);
        $this->assertSame(['With Dept'], $totals['labels']);
    }
}
