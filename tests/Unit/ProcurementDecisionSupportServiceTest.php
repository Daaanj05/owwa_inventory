<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\ProcurementDecisionSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementDecisionSupportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ProcurementDecisionSupportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProcurementDecisionSupportService::class);
    }

    public function test_high_priority_when_cover_under_one_month(): void
    {
        [$item, $office] = $this->seedAtRiskPair(monthlyQty: 10, stock: 5, reorderLevel: 2);

        $rows = $this->queryRows($office->id);

        $match = $rows->first(fn ($row) => $row->item_id === $item->id);
        $this->assertNotNull($match);
        $this->assertSame('High', $match->priority);
        $this->assertLessThan(1.0, (float) $match->months_cover);
    }

    public function test_medium_priority_when_cover_between_one_and_three_months(): void
    {
        [$item, $office] = $this->seedAtRiskPair(monthlyQty: 10, stock: 25, reorderLevel: 2);

        $rows = $this->queryRows($office->id);

        $match = $rows->first(fn ($row) => $row->item_id === $item->id);
        $this->assertNotNull($match);
        $this->assertSame('Medium', $match->priority);
        $this->assertGreaterThanOrEqual(1.0, (float) $match->months_cover);
        $this->assertLessThanOrEqual(3.0, (float) $match->months_cover);
    }

    public function test_low_priority_pairs_are_omitted_from_at_risk_rows(): void
    {
        [$item, $office] = $this->seedAtRiskPair(monthlyQty: 5, stock: 100, reorderLevel: 2);

        $rows = $this->queryRows($office->id);

        $this->assertNull($rows->first(fn ($row) => $row->item_id === $item->id));
    }

    public function test_suggested_reorder_uses_target_cover_formula(): void
    {
        [$item, $office] = $this->seedAtRiskPair(monthlyQty: 10, stock: 5, reorderLevel: 2);

        $rows = $this->queryRows($office->id);
        $match = $rows->first(fn ($row) => $row->item_id === $item->id);

        $this->assertNotNull($match);
        $forecast = (float) $match->forecast_monthly_usage;
        $forecastBased = (int) max(0, ceil((3 * $forecast) - $match->current_stock));
        $reorderFloor = max(1, 2 - $match->current_stock);
        $expected = max($forecastBased, $reorderFloor);
        $this->assertSame($expected, $match->suggested_reorder_qty);
        $this->assertGreaterThan(0, $match->suggested_reorder_qty);
    }

    public function test_category_filter_limits_at_risk_rows(): void
    {
        $office = Office::factory()->create();
        $categoryA = ItemCategory::factory()->create(['name' => 'Cat A']);
        $categoryB = ItemCategory::factory()->create(['name' => 'Cat B']);

        $itemA = Item::factory()->create(['item_category_id' => $categoryA->id, 'reorder_level' => 2]);
        $itemB = Item::factory()->create(['item_category_id' => $categoryB->id, 'reorder_level' => 2]);

        $this->seedMonthlyIssuances($itemA->id, $office->id, 10, 6);
        $this->seedMonthlyIssuances($itemB->id, $office->id, 10, 6);
        $this->createAcquisition($itemA->id, $office->id, 5 + 60);
        $this->createAcquisition($itemB->id, $office->id, 5 + 60);

        $filtered = $this->service->getAtRiskRows(
            from: now()->subMonths(5)->startOfMonth(),
            to: now()->endOfMonth(),
            categoryId: $categoryA->id,
            officeIds: [$office->id],
            limit: 50,
        );

        $itemIds = $filtered->pluck('item_id')->all();
        $this->assertContains($itemA->id, $itemIds);
        $this->assertNotContains($itemB->id, $itemIds);
    }

    public function test_coverage_summary_respects_category_filter(): void
    {
        $office = Office::factory()->create();
        $categoryA = ItemCategory::factory()->create(['name' => 'Cat A']);
        $categoryB = ItemCategory::factory()->create(['name' => 'Cat B']);

        $itemA = Item::factory()->create(['item_category_id' => $categoryA->id, 'reorder_level' => 2]);
        $itemB = Item::factory()->create(['item_category_id' => $categoryB->id, 'reorder_level' => 2]);

        $this->seedMonthlyIssuances($itemA->id, $office->id, 10, 6);
        $this->seedMonthlyIssuances($itemB->id, $office->id, 10, 6);
        $this->createAcquisition($itemA->id, $office->id, 5 + 60);
        $this->createAcquisition($itemB->id, $office->id, 5 + 60);

        $from = now()->subMonths(5)->startOfMonth();
        $to = now()->endOfMonth();

        $allSummary = $this->service->getCoverageSummary($from, $to, null, [$office->id]);
        $filteredSummary = $this->service->getCoverageSummary($from, $to, $categoryA->id, [$office->id]);

        $this->assertGreaterThanOrEqual(2, $allSummary['pairs']);
        $this->assertSame(1, $filteredSummary['pairs']);
        $this->assertGreaterThan($filteredSummary['pairs'], $allSummary['pairs']);
    }

    public function test_below_reorder_with_high_cover_is_high_priority(): void
    {
        [$item, $office] = $this->seedAtRiskPair(monthlyQty: 1, stock: 8, reorderLevel: 10);

        $rows = $this->queryRows($office->id);
        $match = $rows->first(fn ($row) => $row->item_id === $item->id);

        $this->assertNotNull($match);
        $this->assertSame('High', $match->priority);
        $this->assertGreaterThan(3.0, (float) $match->months_cover);
    }

    public function test_suggested_reorder_uses_reorder_floor_when_forecast_target_is_met(): void
    {
        [$item, $office] = $this->seedAtRiskPair(monthlyQty: 1, stock: 8, reorderLevel: 10);

        $rows = $this->queryRows($office->id);
        $match = $rows->first(fn ($row) => $row->item_id === $item->id);

        $this->assertNotNull($match);
        $this->assertSame(2, $match->suggested_reorder_qty);
    }

    public function test_stock_equal_to_reorder_without_usage_is_not_at_risk(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 30,
        ]);

        $this->createAcquisition($item->id, $office->id, 30);

        $rows = $this->service->getAtRiskRows(
            from: now()->subMonths(2)->startOfMonth(),
            to: now()->endOfMonth(),
            categoryId: null,
            officeIds: [$office->id],
            limit: 50,
        );

        $this->assertNull($rows->first(fn ($row) => $row->item_id === $item->id));
    }

    public function test_forecast_uses_twelve_month_lookback_when_ui_window_is_short(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 2,
        ]);

        $this->seedMonthlyIssuances($item->id, $office->id, 10, 12);
        $this->createAcquisition($item->id, $office->id, 15 + 120);

        $shortWindow = $this->service->getAtRiskRows(
            from: now()->subMonths(2)->startOfMonth(),
            to: now()->endOfMonth(),
            officeIds: [$office->id],
            limit: 50,
        );

        $longWindow = $this->service->getAtRiskRows(
            from: now()->subMonths(11)->startOfMonth(),
            to: now()->endOfMonth(),
            officeIds: [$office->id],
            limit: 50,
        );

        $short = $shortWindow->first(fn ($row) => $row->item_id === $item->id);
        $long = $longWindow->first(fn ($row) => $row->item_id === $item->id);

        $this->assertNotNull($short);
        $this->assertNotNull($long);
        $this->assertSame((float) $long->forecast_monthly_usage, (float) $short->forecast_monthly_usage);
        $this->assertSame((float) $long->months_cover, (float) $short->months_cover);
    }

    public function test_includes_low_stock_pairs_without_recent_issuances(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 10,
        ]);

        $this->createAcquisition($item->id, $office->id, 4);

        $rows = $this->service->getAtRiskRows(
            from: now()->subMonths(2)->startOfMonth(),
            to: now()->endOfMonth(),
            categoryId: null,
            officeIds: [$office->id],
            limit: 50,
        );

        $match = $rows->first(fn ($row) => $row->item_id === $item->id);
        $this->assertNotNull($match);
        $this->assertFalse($match->has_recent_usage);
        $this->assertSame('High', $match->priority);
        $this->assertSame(0.0, (float) $match->forecast_monthly_usage);
        $this->assertSame(6, $match->suggested_reorder_qty);
    }

    /**
     * @return array{0: Item, 1: Office}
     */
    protected function seedAtRiskPair(int $monthlyQty, int $stock, int $reorderLevel): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => $reorderLevel,
        ]);

        $months = 6;
        $this->seedMonthlyIssuances($item->id, $office->id, $monthlyQty, $months);
        $this->createAcquisition($item->id, $office->id, $stock + ($monthlyQty * $months));

        return [$item, $office];
    }

    protected function queryRows(int $officeId): \Illuminate\Support\Collection
    {
        return $this->service->getAtRiskRows(
            from: now()->subMonths(5)->startOfMonth(),
            to: now()->endOfMonth(),
            categoryId: null,
            officeIds: [$officeId],
            limit: 50,
        );
    }

    protected function seedMonthlyIssuances(int $itemId, int $officeId, int $monthlyQty, int $months): void
    {
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i)->startOfMonth()->toDateString();
            DB::table('issuances')->insert([
                'reference_code' => 'ISS-TEST-'.$itemId.'-'.$officeId.'-'.$i.'-'.uniqid(),
                'item_id' => $itemId,
                'office_id' => $officeId,
                'quantity' => $monthlyQty,
                'issuance_date' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function createAcquisition(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-TEST-'.$itemId.'-'.$officeId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
