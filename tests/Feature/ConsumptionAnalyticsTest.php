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
        $totals = $service->getConsumptionTotalsByOffice($from, $to);

        $this->assertSame(15, $totals['total']);
        $this->assertContains('Regional Office', $totals['labels']);
        $this->assertContains('Satellite Office', $totals['labels']);
    }

    public function test_consumption_can_filter_to_satellite_office_only(): void
    {
        $regional = Office::factory()->create(['name' => 'Regional Office', 'is_satellite' => false]);
        $satellite = Office::factory()->create(['name' => 'Satellite Office', 'is_satellite' => true]);
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
        $totals = $service->getConsumptionTotalsByOffice($from, $to, officeIds: [$satellite->id]);

        $this->assertSame(7, $totals['total']);
        $this->assertSame(['Satellite Office'], $totals['labels']);
    }

    public function test_consumption_summary_returns_top_office(): void
    {
        $officeA = Office::factory()->create(['name' => 'Regional Office']);
        $officeB = Office::factory()->create(['name' => 'Satellite Office']);
        $deptA = Department::query()->create([
            'office_id' => $officeA->id,
            'name' => 'Admin',
            'code' => 'AD',
        ]);
        $deptB = Department::query()->create([
            'office_id' => $officeB->id,
            'name' => 'Finance',
            'code' => 'FN',
        ]);
        $item = Item::factory()->create();

        $from = Carbon::parse('2026-03-01');
        $to = Carbon::parse('2026-03-31');

        Issuance::withoutEvents(function () use ($item, $officeA, $officeB, $deptA, $deptB, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-A',
                'item_id' => $item->id,
                'office_id' => $officeA->id,
                'department_id' => $deptA->id,
                'quantity' => 30,
                'issuance_date' => $from->copy()->addDay(),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-B',
                'item_id' => $item->id,
                'office_id' => $officeB->id,
                'department_id' => $deptB->id,
                'quantity' => 50,
                'issuance_date' => $from->copy()->addDays(2),
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $summary = $service->getConsumptionSummaryByOffice($from, $to);

        $this->assertSame(80, $summary['total']);
        $this->assertSame('Satellite Office', $summary['top_office_name']);
        $this->assertSame(50, $summary['top_office_quantity']);
    }

    public function test_multiple_departments_in_same_office_aggregate_into_one_office_slice(): void
    {
        $office = Office::factory()->create(['name' => 'Regional Office']);
        $deptA = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin Division',
            'code' => 'AD',
        ]);
        $deptB = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Finance Division',
            'code' => 'FD',
        ]);
        $item = Item::factory()->create();

        $from = Carbon::parse('2026-04-01');
        $to = Carbon::parse('2026-04-30');

        Issuance::withoutEvents(function () use ($item, $office, $deptA, $deptB, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-AD',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => $deptA->id,
                'quantity' => 12,
                'issuance_date' => $from->copy()->addDay(),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-FD',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => $deptB->id,
                'quantity' => 8,
                'issuance_date' => $from->copy()->addDays(2),
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $totals = $service->getConsumptionTotalsByOffice($from, $to);

        $this->assertSame(20, $totals['total']);
        $this->assertSame(['Regional Office'], $totals['labels']);
        $this->assertSame([20], $totals['values']);
    }

    public function test_department_filter_narrows_office_totals(): void
    {
        $office = Office::factory()->create(['name' => 'Regional Office']);
        $deptA = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin Division',
            'code' => 'AD',
        ]);
        $deptB = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Finance Division',
            'code' => 'FD',
        ]);
        $item = Item::factory()->create();

        $from = Carbon::parse('2026-05-01');
        $to = Carbon::parse('2026-05-31');

        Issuance::withoutEvents(function () use ($item, $office, $deptA, $deptB, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-AD-2',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => $deptA->id,
                'quantity' => 15,
                'issuance_date' => $from->copy()->addDay(),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-FD-2',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => $deptB->id,
                'quantity' => 25,
                'issuance_date' => $from->copy()->addDays(2),
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $totals = $service->getConsumptionTotalsByOffice($from, $to, departmentIds: [$deptA->id]);

        $this->assertSame(15, $totals['total']);
        $this->assertSame(['Regional Office'], $totals['labels']);
        $this->assertSame([15], $totals['values']);
    }
}
