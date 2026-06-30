<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryStockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryStockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InventoryStockService::class);
    }

    public function test_get_stock_returns_zero_when_no_transactions(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $office = Office::factory()->create();

        $stock = $this->service->getStock($item->id, $office->id);

        $this->assertSame(0, $stock);
    }

    public function test_catalog_item_not_in_stock_levels_list_and_not_low_stock(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 10,
        ]);
        $office = Office::factory()->create();

        $this->assertFalse($this->service->isLowStock($item, $office->id));
        $this->assertFalse($this->service->hasInventoryActivity($item->id, $office->id));
        $this->assertCount(0, $this->service->getStockLevelsList());
        $this->assertSame(0, $this->service->lowStockCount());
    }

    public function test_item_appears_in_stock_levels_after_acquisition(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 10,
        ]);
        $office = Office::factory()->create();

        $this->createAcquisition($item->id, $office->id, 25);

        $list = $this->service->getStockLevelsList();
        $this->assertCount(1, $list);
        $this->assertSame(25, $list->first()->stock);
        $this->assertSame($item->id, $list->first()->item_id);
        $this->assertSame($office->id, $list->first()->office_id);
    }

    public function test_depleted_item_remains_in_stock_levels_after_full_issuance(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 5,
        ]);
        $office = Office::factory()->create();

        $this->createAcquisition($item->id, $office->id, 10);
        $this->createIssuance($item->id, $office->id, 10);

        $list = $this->service->getStockLevelsList();
        $this->assertCount(1, $list);
        $this->assertSame(0, $list->first()->stock);
        $this->assertTrue($list->first()->is_low);
        $this->assertTrue($this->service->hasInventoryActivity($item->id, $office->id));
    }

    public function test_transfer_in_shows_stock_at_destination_without_local_acquisition(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $fromOffice = Office::factory()->create(['code' => 'SRC']);
        $toOffice = Office::factory()->create(['code' => 'DST']);

        $this->createAcquisition($item->id, $fromOffice->id, 20);
        $this->createTransfer($item->id, $fromOffice->id, $toOffice->id, 8);

        $list = $this->service->getStockLevelsList();
        $destination = $list->first(fn ($row) => $row->office_id === $toOffice->id);

        $this->assertNotNull($destination);
        $this->assertSame(8, $destination->stock);
        $this->assertFalse($list->contains(fn ($row) => $row->office_id === $toOffice->id && $row->stock === 0 && ! $this->service->hasInventoryActivity($item->id, $toOffice->id)));
    }

    public function test_low_stock_count_ignores_catalog_only_pairs(): void
    {
        $category = ItemCategory::factory()->create();
        $catalogOnly = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 10,
        ]);
        $stocked = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 10,
        ]);
        $office = Office::factory()->create();

        $this->createAcquisition($stocked->id, $office->id, 3);

        $this->assertFalse($this->service->hasInventoryActivity($catalogOnly->id, $office->id));
        $this->assertSame(1, $this->service->lowStockCount());
    }

    public function test_stock_equal_to_reorder_is_not_low_stock(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 30,
        ]);
        $office = Office::factory()->create();

        $this->createAcquisition($item->id, $office->id, 30);

        $this->assertFalse($this->service->isLowStock($item, $office->id));

        $list = $this->service->getStockLevelsList();
        $this->assertFalse($list->first()->is_low);
        $this->assertSame(0, $this->service->lowStockCount());
    }

    public function test_is_low_stock_returns_false_without_inventory_activity(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'reorder_level' => 10,
        ]);
        $office = Office::factory()->create();

        $this->assertFalse($this->service->isLowStock($item, $office->id));
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

    protected function createIssuance(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('issuances')->insert([
            'reference_code' => 'ISS-TEST-'.$itemId.'-'.$officeId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'issuance_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createTransfer(int $itemId, int $fromOfficeId, int $toOfficeId, int $quantity): void
    {
        DB::table('transfers')->insert([
            'reference_code' => 'TRF-TEST-'.$itemId.'-'.uniqid(),
            'item_id' => $itemId,
            'from_office_id' => $fromOfficeId,
            'to_office_id' => $toOfficeId,
            'quantity' => $quantity,
            'transfer_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
