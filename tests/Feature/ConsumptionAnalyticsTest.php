<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\Office;
use App\Models\User;
use App\Services\ConsumptionAnalyticsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConsumptionAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumption_includes_multiple_offices_by_default(): void
    {
        $regional = Office::factory()->create(['name' => 'Regional Office']);
        $satellite = Office::factory()->create(['name' => 'Satellite Office']);
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

    public function test_office_series_include_zero_offices_with_no_issuances(): void
    {
        $regional = Office::factory()->create(['name' => 'Regional Office']);
        $satellite = Office::factory()->create(['name' => 'Satellite Office']);
        $item = Item::factory()->create();

        $from = Carbon::parse('2026-04-01');
        $to = Carbon::parse('2026-09-30');

        Issuance::withoutEvents(function () use ($item, $regional): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-REG-ONLY',
                'item_id' => $item->id,
                'office_id' => $regional->id,
                'department_id' => null,
                'quantity' => 12,
                'issuance_date' => '2026-05-15',
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $period = $service->getConsumptionByOfficeAndPeriod($from, $to);
        $totals = $service->getConsumptionTotalsByOffice($from, $to);

        $this->assertArrayHasKey('Regional Office', $period['series']);
        $this->assertArrayHasKey('Satellite Office', $period['series']);
        $this->assertSame(12, array_sum($period['series']['Regional Office']));
        $this->assertSame(0, array_sum($period['series']['Satellite Office']));

        $this->assertSame(12, $totals['total']);
        $this->assertContains('Regional Office', $totals['labels']);
        $this->assertContains('Satellite Office', $totals['labels']);
        $satelliteIndex = array_search('Satellite Office', $totals['labels'], true);
        $this->assertNotFalse($satelliteIndex);
        $this->assertSame(0, $totals['values'][$satelliteIndex]);
    }

    public function test_consumption_can_filter_to_one_office_only(): void
    {
        $regional = Office::factory()->create(['name' => 'Regional Office']);
        $satellite = Office::factory()->create(['name' => 'Satellite Office']);
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

    public function test_department_series_break_out_selected_departments_with_zeros(): void
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

        $from = Carbon::parse('2026-06-01');
        $to = Carbon::parse('2026-06-30');

        Issuance::withoutEvents(function () use ($item, $office, $deptA, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-DEPT-A',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => $deptA->id,
                'quantity' => 18,
                'issuance_date' => $from->copy()->addDay(),
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $period = $service->getConsumptionByDepartmentAndPeriod(
            $from,
            $to,
            departmentIds: [$deptA->id, $deptB->id],
            officeIds: [$office->id],
        );
        $totals = $service->getConsumptionTotalsByDepartment(
            $from,
            $to,
            departmentIds: [$deptA->id, $deptB->id],
            officeIds: [$office->id],
        );
        $summary = $service->getConsumptionSummary(
            $from,
            $to,
            departmentIds: [$deptA->id, $deptB->id],
            officeIds: [$office->id],
        );

        $this->assertArrayHasKey('Admin Division', $period['series']);
        $this->assertArrayHasKey('Finance Division', $period['series']);
        $this->assertSame([18], $period['series']['Admin Division']);
        $this->assertSame([0], $period['series']['Finance Division']);

        $this->assertSame(18, $totals['total']);
        $this->assertSame(['Admin Division', 'Finance Division'], $totals['labels']);
        $this->assertSame([18, 0], $totals['values']);
        $this->assertSame('Admin Division', $summary['top_department_name']);
        $this->assertSame(18, $summary['top_department_quantity']);
    }

    public function test_trend_widget_switches_to_department_series_for_one_office(): void
    {
        $office = Office::factory()->create(['name' => 'Regional Office', 'is_regional_supply' => true]);
        $otherOffice = Office::factory()->create(['name' => 'Satellite Office']);
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
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $from = Carbon::parse('2026-07-01');
        $to = Carbon::parse('2026-07-31');

        Issuance::withoutEvents(function () use ($item, $office, $deptA, $deptB, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-WIDGET-AD',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => $deptA->id,
                'quantity' => 9,
                'issuance_date' => $from->copy()->addDay(),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-WIDGET-FD',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => $deptB->id,
                'quantity' => 4,
                'issuance_date' => $from->copy()->addDays(2),
            ]);
        });

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $widget = new class extends \App\Filament\Widgets\ConsumptionTrendsWidget
        {
            public function mountForTest(): void
            {
                $this->bootConsumptionFilterDefaults();
            }

            public function exposeData(): array
            {
                return $this->getData();
            }

            public function exposeResolved(?array $filters = null): array
            {
                return $this->resolveConsumptionFilters($filters);
            }

            public function exposeDepartmentMode(?array $filters = null): bool
            {
                return $this->isConsumptionDepartmentMode($filters);
            }

            public function exposeOfficeContextLabel(?array $filters = null): ?string
            {
                return $this->getConsumptionOfficeContextLabel($filters);
            }

            public function setFiltersForTest(array $filters): void
            {
                $this->filters = $filters;
            }
        };
        $widget->mountForTest();

        $widget->setFiltersForTest([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'office_ids' => [(string) $office->id],
            'department_ids' => [(string) $deptA->id, (string) $deptB->id],
            'item_category_ids' => '',
            'base_names' => '',
            'item_ids' => '',
        ]);

        $this->assertTrue($widget->exposeDepartmentMode());
        $this->assertSame('Regional Office', $widget->exposeOfficeContextLabel());
        $data = $widget->exposeData();
        $labels = collect($data['datasets'])->pluck('label')->all();
        $this->assertContains('Admin Division', $labels);
        $this->assertContains('Finance Division', $labels);
        $this->assertNotContains('Regional Office', $labels);

        $summary = $widget->getConsumptionSummary();
        $this->assertSame('department', $summary['mode']);
        $this->assertSame('Admin Division', $summary['top_name']);
        $this->assertSame(9, $summary['top_quantity']);

        $cleared = $widget->exposeResolved([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'office_ids' => [(string) $office->id, (string) $otherOffice->id],
            'department_ids' => [(string) $deptA->id],
        ]);
        $this->assertSame([], $cleared['department_ids']);
        $this->assertFalse($widget->exposeDepartmentMode([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'office_ids' => [(string) $office->id, (string) $otherOffice->id],
            'department_ids' => [(string) $deptA->id],
        ]));
    }

    public function test_item_ids_filter_narrows_office_totals(): void
    {
        $office = Office::factory()->create(['name' => 'Regional Office']);
        $dept = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin Division',
            'code' => 'AD',
        ]);
        $bondA4 = Item::factory()->create([
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
        ]);
        $bondLong = Item::factory()->create([
            'base_name' => 'Bond Paper',
            'sub_item' => 'Long',
            'name' => 'Bond Paper Long',
        ]);

        $from = Carbon::parse('2026-06-01');
        $to = Carbon::parse('2026-06-30');

        Issuance::withoutEvents(function () use ($bondA4, $bondLong, $office, $dept, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-A4',
                'item_id' => $bondA4->id,
                'office_id' => $office->id,
                'department_id' => $dept->id,
                'quantity' => 10,
                'issuance_date' => $from->copy()->addDay(),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-LONG',
                'item_id' => $bondLong->id,
                'office_id' => $office->id,
                'department_id' => $dept->id,
                'quantity' => 7,
                'issuance_date' => $from->copy()->addDays(2),
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $totals = $service->getConsumptionTotalsByOffice(
            $from,
            $to,
            itemIds: [$bondA4->id],
        );

        $this->assertSame(10, $totals['total']);
        $this->assertSame(['Regional Office'], $totals['labels']);
        $this->assertSame([10], $totals['values']);
    }

    public function test_consumption_totals_by_item_splits_selected_items(): void
    {
        $office = Office::factory()->create(['name' => 'Regional Office']);
        $dept = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin Division',
            'code' => 'AD',
        ]);
        $bondA4 = Item::factory()->create([
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
        ]);
        $bondLong = Item::factory()->create([
            'base_name' => 'Bond Paper',
            'sub_item' => 'Long',
            'name' => 'Bond Paper Long',
        ]);

        $from = Carbon::parse('2026-07-01');
        $to = Carbon::parse('2026-07-31');

        Issuance::withoutEvents(function () use ($bondA4, $bondLong, $office, $dept, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-A4-ITEM',
                'item_id' => $bondA4->id,
                'office_id' => $office->id,
                'department_id' => $dept->id,
                'quantity' => 12,
                'issuance_date' => $from->copy()->addDay(),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-LONG-ITEM',
                'item_id' => $bondLong->id,
                'office_id' => $office->id,
                'department_id' => $dept->id,
                'quantity' => 8,
                'issuance_date' => $from->copy()->addDays(2),
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $totals = $service->getConsumptionTotalsByItem($from, $to);

        $this->assertSame(20, $totals['total']);
        $this->assertContains('A4 — Bond Paper A4', $totals['labels']);
        $this->assertContains('Long — Bond Paper Long', $totals['labels']);

        $summary = $service->getConsumptionSummaryByItem($from, $to);
        $this->assertSame('A4 — Bond Paper A4', $summary['top_item_name']);
        $this->assertSame(12, $summary['top_item_quantity']);

        $period = $service->getConsumptionByItemAndPeriod($from, $to, itemIds: [$bondLong->id]);
        $this->assertArrayHasKey('Long — Bond Paper Long', $period['series']);
        $this->assertSame(8, array_sum($period['series']['Long — Bond Paper Long']));
        $this->assertCount(1, $period['series']);
    }

    public function test_office_and_item_series_labels_include_both_names(): void
    {
        $office = Office::factory()->create(['name' => 'Regional Office']);
        $dept = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin Division',
            'code' => 'AD',
        ]);
        $bondA4 = Item::factory()->create([
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
        ]);
        $bondLong = Item::factory()->create([
            'base_name' => 'Bond Paper',
            'sub_item' => 'Long',
            'name' => 'Bond Paper Long',
        ]);

        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-08-31');

        Issuance::withoutEvents(function () use ($bondA4, $bondLong, $office, $dept, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-OI-A4',
                'item_id' => $bondA4->id,
                'office_id' => $office->id,
                'department_id' => $dept->id,
                'quantity' => 4,
                'issuance_date' => $from->copy()->addDay(),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-OI-LONG',
                'item_id' => $bondLong->id,
                'office_id' => $office->id,
                'department_id' => $dept->id,
                'quantity' => 6,
                'issuance_date' => $from->copy()->addDays(2),
            ]);
        });

        $service = app(ConsumptionAnalyticsService::class);
        $totals = $service->getConsumptionTotalsByOfficeAndItem(
            $from,
            $to,
            itemIds: [$bondA4->id, $bondLong->id],
        );

        $this->assertSame(10, $totals['total']);
        $this->assertContains('Regional Office — A4 — Bond Paper A4', $totals['labels']);
        $this->assertContains('Regional Office — Long — Bond Paper Long', $totals['labels']);

        $summary = $service->getConsumptionSummaryByOffice(
            $from,
            $to,
            itemIds: [$bondA4->id, $bondLong->id],
        );
        $this->assertSame('Regional Office', $summary['top_office_name']);
        $this->assertSame(10, $summary['top_office_quantity']);
    }

    public function test_resolve_consumption_filters_defaults_to_all_offices(): void
    {
        $office = Office::factory()->create([
            'name' => 'Regional Supply',
            'is_regional_supply' => true,
        ]);
        Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);

        $widget = new class extends \App\Filament\Widgets\ConsumptionTrendsWidget
        {
            public function mountForTest(): void
            {
                $this->bootConsumptionFilterDefaults();
            }

            public function exposeDepartments(array $officeIds): array
            {
                return $this->departmentOptionsForOffices($officeIds);
            }

            public function exposeResolved(?array $filters): array
            {
                return $this->resolveConsumptionFilters($filters);
            }

            public function exposeFilters(): array
            {
                return $this->filters ?? [];
            }
        };

        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        $custodian = \App\Models\User::factory()->create([
            'role' => \App\Models\User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($custodian);

        $widget->mountForTest();
        $booted = $widget->exposeFilters();
        $this->assertSame([], $booted['office_ids'] ?? []);
        $this->assertNotEmpty($booted['date_from'] ?? null);
        $this->assertNotEmpty($booted['date_to'] ?? null);

        $this->assertNotEmpty($widget->exposeDepartments([(int) $office->id]));

        $resolvedEmpty = $widget->exposeResolved([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);
        $this->assertSame([], $resolvedEmpty['office_ids']);
        $this->assertSame([], $resolvedEmpty['item_ids']);

        $resolvedWithOffice = $widget->exposeResolved([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'office_ids' => [(string) $office->id],
        ]);
        $this->assertSame([(int) $office->id], $resolvedWithOffice['office_ids']);
        $this->assertSame([], $resolvedWithOffice['item_ids']);
    }

    public function test_consumption_summary_avg_uses_selected_month_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $item = Item::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        Issuance::withoutEvents(function () use ($item, $office): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-AVG-1',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => null,
                'quantity' => 30,
                'issuance_date' => '2026-04-10',
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-AVG-2',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'department_id' => null,
                'quantity' => 30,
                'issuance_date' => '2026-09-05',
            ]);
        });

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $summary = app(\App\Services\ConsumptionAnalyticsService::class)->getConsumptionSummaryByOffice(
            Carbon::parse('2026-04-01')->startOfDay(),
            Carbon::parse('2026-09-30')->endOfDay(),
        );

        $this->assertSame(60, $summary['total']);
        $this->assertSame(6, $summary['periods_count']);
        $this->assertSame(10.0, $summary['avg_per_period']);

        Carbon::setTestNow();
    }

    public function test_category_and_base_filters_resolve_item_ids_for_item_series(): void
    {
        $category = \App\Models\ItemCategory::query()->create(['name' => 'Consumables']);
        $bondA4 = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
        ]);
        $alcohol = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Alcohol 70%',
            'sub_item' => null,
            'name' => 'Alcohol 70%',
        ]);

        $widget = new class extends \App\Filament\Widgets\ConsumptionTrendsWidget
        {
            public function exposeResolved(?array $filters): array
            {
                return $this->resolveConsumptionFilters($filters);
            }

            public function exposeItemScoped(?array $filters): bool
            {
                return $this->isConsumptionItemScoped($filters);
            }

            public function exposeItemContextLabel(?array $filters = null): ?string
            {
                return $this->getConsumptionItemContextLabel($filters);
            }
        };

        $categoryOnly = $widget->exposeResolved([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'item_category_ids' => (string) $category->id,
        ]);
        $this->assertEqualsCanonicalizing(
            [(int) $bondA4->id, (int) $alcohol->id],
            $categoryOnly['item_ids'],
        );
        $this->assertTrue($widget->exposeItemScoped([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'item_category_ids' => (string) $category->id,
        ]));
        $this->assertNull($widget->exposeItemContextLabel([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'item_category_ids' => (string) $category->id,
        ]));

        $resolved = $widget->exposeResolved([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'item_category_ids' => (string) $category->id,
            'base_names' => 'Bond Paper',
        ]);

        $this->assertTrue($widget->exposeItemScoped([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'item_category_ids' => (string) $category->id,
            'base_names' => 'Bond Paper',
        ]));
        $this->assertSame([(int) $bondA4->id], $resolved['item_ids']);
        $this->assertSame('Bond Paper', $widget->exposeItemContextLabel([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'item_category_ids' => (string) $category->id,
            'base_names' => 'Bond Paper',
        ]));
        $this->assertSame('A4', $widget->exposeItemContextLabel([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
            'item_ids' => (string) $bondA4->id,
        ]));
        $this->assertFalse($widget->exposeItemScoped([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]));
    }

    public function test_item_filter_keeps_office_series_not_office_item_labels(): void
    {
        $officeA = Office::factory()->create(['name' => 'Regional Office']);
        $officeB = Office::factory()->create(['name' => 'Field Office']);
        $deptA = Department::query()->create([
            'office_id' => $officeA->id,
            'name' => 'Admin Division',
            'code' => 'AD',
        ]);
        $deptB = Department::query()->create([
            'office_id' => $officeB->id,
            'name' => 'Ops Division',
            'code' => 'OPS',
        ]);
        $category = \App\Models\ItemCategory::query()->create(['name' => 'Consumables Filter']);
        $bondA4 = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Bond Paper',
            'sub_item' => 'A4',
            'name' => 'Bond Paper A4',
        ]);
        $otherItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'base_name' => 'Alcohol 70%',
            'sub_item' => null,
            'name' => 'Alcohol 70%',
        ]);

        $from = Carbon::parse('2026-06-01');
        $to = Carbon::parse('2026-06-30');

        Issuance::withoutEvents(function () use ($bondA4, $otherItem, $officeA, $officeB, $deptA, $deptB, $from): void {
            Issuance::query()->create([
                'reference_code' => 'ISS-OFF-A4-A',
                'item_id' => $bondA4->id,
                'office_id' => $officeA->id,
                'department_id' => $deptA->id,
                'quantity' => 10,
                'issuance_date' => $from->copy()->addDay(),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-OFF-A4-B',
                'item_id' => $bondA4->id,
                'office_id' => $officeB->id,
                'department_id' => $deptB->id,
                'quantity' => 4,
                'issuance_date' => $from->copy()->addDays(2),
            ]);
            Issuance::query()->create([
                'reference_code' => 'ISS-OFF-ALC',
                'item_id' => $otherItem->id,
                'office_id' => $officeA->id,
                'department_id' => $deptA->id,
                'quantity' => 50,
                'issuance_date' => $from->copy()->addDays(3),
            ]);
        });

        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $officeA->id,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $widget = new class extends \App\Filament\Widgets\ConsumptionTrendsWidget
        {
            public function mountForTest(array $filters): void
            {
                $this->bootConsumptionFilterDefaults();
                $this->filters = array_merge($this->filters ?? [], $filters);
            }

            public function exposeData(): array
            {
                return $this->getData();
            }

            public function exposeItemContextLabel(): ?string
            {
                return $this->getConsumptionItemContextLabel();
            }
        };
        $widget->mountForTest([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'office_ids' => [(string) $officeA->id, (string) $officeB->id],
            'item_category_ids' => (string) $category->id,
            'base_names' => 'Bond Paper',
            'item_ids' => '',
        ]);

        $labels = collect($widget->exposeData()['datasets'])->pluck('label')->all();
        $this->assertContains('Regional Office', $labels);
        $this->assertContains('Field Office', $labels);
        $this->assertSame([], array_values(array_filter(
            $labels,
            static fn (string $label): bool => str_contains($label, ' — '),
        )));
        $this->assertSame('Bond Paper', $widget->exposeItemContextLabel());

        $summary = $widget->getConsumptionSummary();
        $this->assertSame('office', $summary['mode']);
        $this->assertSame(14, $summary['total']);
        $this->assertSame('Regional Office', $summary['top_name']);

        $share = new class extends \App\Filament\Widgets\ConsumptionSharePieWidget
        {
            public function mountForTest(array $filters): void
            {
                $this->bootConsumptionFilterDefaults();
                $this->filters = array_merge($this->filters ?? [], $filters);
            }

            public function exposeData(): array
            {
                return $this->getData();
            }
        };
        $share->mountForTest([
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'office_ids' => [(string) $officeA->id, (string) $officeB->id],
            'item_category_ids' => (string) $category->id,
            'base_names' => 'Bond Paper',
            'item_ids' => '',
        ]);

        $this->assertTrue($share->hasConsumptionShareData());
        $shareLabels = $share->exposeData()['labels'];
        $this->assertContains('Regional Office', $shareLabels);
        $this->assertContains('Field Office', $shareLabels);
        $this->assertSame([], array_values(array_filter(
            $shareLabels,
            static fn (string $label): bool => str_contains($label, ' — '),
        )));
    }

    public function test_consumption_analytics_excludes_property_plant_and_equipment(): void
    {
        $consumables = \App\Models\ItemCategory::query()->firstOrCreate(['name' => 'Consumables']);
        $ppe = \App\Models\ItemCategory::query()->firstOrCreate(['name' => 'Property, Plant and Equipment']);
        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Administration',
            'code' => 'ADMIN',
        ]);
        $consumableItem = Item::factory()->create(['item_category_id' => $consumables->id]);
        $ppeItem = Item::factory()->create(['item_category_id' => $ppe->id]);

        Issuance::withoutEvents(function () use ($consumableItem, $ppeItem, $office, $department): void {
            foreach ([[$consumableItem, 5], [$ppeItem, 20]] as [$item, $quantity]) {
                Issuance::query()->create([
                    'reference_code' => 'ISS-'.$item->id,
                    'item_id' => $item->id,
                    'office_id' => $office->id,
                    'department_id' => $department->id,
                    'quantity' => $quantity,
                    'issuance_date' => '2026-06-15',
                ]);
            }
        });

        $totals = app(ConsumptionAnalyticsService::class)->getConsumptionTotalsByOffice(
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        );

        $this->assertSame(5, $totals['total']);
    }

    public function test_consumption_share_accepts_trend_filter_updates(): void
    {
        $widget = new \App\Filament\Widgets\ConsumptionSharePieWidget;

        $widget->syncConsumptionTrendFilters([
            'date_from' => '2026-02-01',
            'date_to' => '2026-05-31',
            'office_ids' => ['2'],
            'department_ids' => ['4'],
            'item_category_ids' => '7',
            'base_names' => 'Bond Paper',
            'item_ids' => '9',
            'show_moving_average' => true,
        ]);

        $this->assertSame('2026-02-01', $widget->filters['date_from']);
        $this->assertSame(['2'], $widget->filters['office_ids']);
        $this->assertSame('7', $widget->filters['item_category_ids']);
        $this->assertArrayNotHasKey('show_moving_average', $widget->filters);
    }

    public function test_consumption_share_shows_placeholder_chart_when_empty(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $widget = new class extends \App\Filament\Widgets\ConsumptionSharePieWidget
        {
            public function mountForTest(): void
            {
                $this->bootConsumptionFilterDefaults();
            }

            public function exposeData(): array
            {
                return $this->getData();
            }
        };
        $widget->mountForTest();

        $this->assertFalse($widget->hasConsumptionShareData());

        $data = $widget->exposeData();
        $this->assertSame(['No issuances yet'], $data['labels']);
        $this->assertSame([1], $data['datasets'][0]['data']);
    }
}
