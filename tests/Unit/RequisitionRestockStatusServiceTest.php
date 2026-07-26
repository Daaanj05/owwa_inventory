<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\Office;
use App\Models\StockPositionRestockFlag;
use App\Services\InventoryStockService;
use App\Services\RequisitionRestockStatusService;
use App\Support\SupplyOfficeResolver;
use App\Support\UnitCostKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionRestockStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_active_when_no_position_is_inactive(): void
    {
        $item = Item::factory()->create();
        $office = Office::factory()->create();
        $service = $this->serviceForPositions($office, [
            UnitCostKey::positionKey($item->id, $office->id, 10),
        ]);

        $this->assertSame(RequisitionRestockStatusService::STATUS_ACTIVE, $service->resolve($item->id));
        $this->assertSame('Active', $service->displayLabel(RequisitionRestockStatusService::STATUS_ACTIVE));
    }

    public function test_it_resolves_manual_and_automatic_inactivity(): void
    {
        $manualItem = Item::factory()->create();
        $automaticItem = Item::factory()->create();
        $office = Office::factory()->create();

        $this->createInactiveFlag($manualItem->id, $office->id, 10, StockPositionRestockFlag::SOURCE_MANUAL);
        $this->createInactiveFlag($automaticItem->id, $office->id, 20, StockPositionRestockFlag::SOURCE_AUTOMATIC);

        $service = $this->serviceForPositions($office, [
            UnitCostKey::positionKey($manualItem->id, $office->id, 10),
            UnitCostKey::positionKey($automaticItem->id, $office->id, 20),
        ]);

        $statuses = $service->resolveForItems([$manualItem->id, $automaticItem->id]);

        $this->assertSame(RequisitionRestockStatusService::STATUS_MANUAL, $statuses[$manualItem->id]);
        $this->assertSame('Inactive', $service->displayLabel($statuses[$manualItem->id]));
        $this->assertSame(RequisitionRestockStatusService::STATUS_AUTOMATIC, $statuses[$automaticItem->id]);
        $this->assertSame('Inactive — no stock for 1 year', $service->displayLabel($statuses[$automaticItem->id]));
    }

    public function test_it_resolves_mixed_when_only_some_positions_are_inactive(): void
    {
        $item = Item::factory()->create();
        $office = Office::factory()->create();

        $this->createInactiveFlag($item->id, $office->id, 10, StockPositionRestockFlag::SOURCE_MANUAL);

        $service = $this->serviceForPositions($office, [
            UnitCostKey::positionKey($item->id, $office->id, 10),
            UnitCostKey::positionKey($item->id, $office->id, 20),
        ]);

        $status = $service->resolve($item->id);

        $this->assertSame(RequisitionRestockStatusService::STATUS_MIXED, $status);
        $this->assertSame('Inactive', $service->displayLabel($status));
    }

    /**
     * @param  array<int, string>  $positionKeys
     */
    private function serviceForPositions(Office $office, array $positionKeys): RequisitionRestockStatusService
    {
        $stockService = $this->createMock(InventoryStockService::class);
        $stockService->method('getActiveStockPositionKeys')
            ->willReturn(array_fill_keys($positionKeys, true));

        $supplyOfficeResolver = $this->createMock(SupplyOfficeResolver::class);
        $supplyOfficeResolver->method('resolve')->willReturn($office->id);

        return new RequisitionRestockStatusService($stockService, $supplyOfficeResolver);
    }

    private function createInactiveFlag(int $itemId, int $officeId, float $unitCost, string $source): void
    {
        StockPositionRestockFlag::query()->create([
            'item_id' => $itemId,
            'office_id' => $officeId,
            'unit_cost' => $unitCost,
            'is_inactive_for_restock' => true,
            'inactive_at' => now(),
            'inactive_source' => $source,
        ]);
    }
}
