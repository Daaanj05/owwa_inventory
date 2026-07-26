<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\Office;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryStockWacTest extends TestCase
{
    use RefreshDatabase;

    public function test_weighted_average_and_value_from_cost_buckets(): void
    {
        $item = Item::factory()->create();
        $office = Office::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'office_id' => $office->id]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-WAC-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'unit_cost' => 10,
            'acquisition_date' => now()->subDays(2)->toDateString(),
            'recorded_by' => $user->id,
        ]);
        Acquisition::query()->create([
            'reference_code' => 'ACQ-WAC-2',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 2,
            'unit_cost' => 20,
            'acquisition_date' => now()->subDay()->toDateString(),
            'recorded_by' => $user->id,
        ]);

        $service = app(InventoryStockService::class);
        $service->forgetMovementTotalsCache();

        $this->assertSame(4, $service->getStock($item->id, $office->id));
        $this->assertSame(15.0, $service->weightedAverageUnitCost($item->id, $office->id));
        $this->assertSame(60.0, $service->totalStockValue($item->id, $office->id));
        $this->assertSame(20.0, $service->latestUnitCost($item->id, $office->id));

        $summary = $service->summarizeStockLevelsByItemOffice($service->getStockLevelsList());
        $row = $summary->firstWhere('item_id', $item->id);

        $this->assertNotNull($row);
        $this->assertSame(4, (int) $row->stock);
        $this->assertSame(15.0, (float) $row->avg_unit_cost);
        $this->assertSame(60.0, (float) $row->stock_value);
        $this->assertSame(20.0, (float) $row->latest_unit_cost);
    }
}
