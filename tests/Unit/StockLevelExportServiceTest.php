<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\StockLevelExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockLevelExportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_encode_and_decode_pair_key_with_unit_cost(): void
    {
        $service = app(StockLevelExportService::class);

        $key = $service->encodePairKey(12, 34, 1500.5);
        $decoded = $service->decodePairKey($key);

        $this->assertNotNull($decoded);
        $this->assertSame(12, $decoded['item_id']);
        $this->assertSame(34, $decoded['office_id']);
        $this->assertSame(1500.5, $decoded['unit_cost']);
    }

    public function test_decode_pair_key_without_unit_cost(): void
    {
        $service = app(StockLevelExportService::class);

        $decoded = $service->decodePairKey('5:9');

        $this->assertNotNull($decoded);
        $this->assertSame(5, $decoded['item_id']);
        $this->assertSame(9, $decoded['office_id']);
        $this->assertNull($decoded['unit_cost']);
    }

    public function test_decode_pair_key_rejects_invalid_values(): void
    {
        $service = app(StockLevelExportService::class);

        $this->assertNull($service->decodePairKey('invalid'));
        $this->assertNull($service->decodePairKey('0:1'));
    }

    public function test_resolve_explicit_pairs_does_not_require_full_stock_list_match(): void
    {
        $service = app(StockLevelExportService::class);

        $this->expectException(ValidationException::class);

        $service->resolvePairs(
            categoryId: null,
            search: null,
            restockFilter: 'active',
            scopedOfficeId: null,
            explicitPairKeys: ['999:888:1'],
        );
    }

    public function test_chunk_pair_keys_splits_by_batch_size(): void
    {
        $service = app(StockLevelExportService::class);
        $keys = [];

        for ($i = 1; $i <= 250; $i++) {
            $keys[] = $i.':1';
        }

        $chunks = $service->chunkPairKeys($keys);

        $this->assertCount(3, $chunks);
        $this->assertCount(StockLevelExportService::BATCH_SIZE, $chunks[0]);
        $this->assertCount(StockLevelExportService::BATCH_SIZE, $chunks[1]);
        $this->assertCount(50, $chunks[2]);
    }

    public function test_export_max_from_request_caps_at_single_max(): void
    {
        $service = app(StockLevelExportService::class);

        $request = Request::create('/export', 'GET', ['export_max' => 9999]);
        $this->assertSame(StockLevelExportService::SINGLE_MAX, $service->exportMaxFromRequest($request));

        $default = Request::create('/export', 'GET');
        $this->assertSame(StockLevelExportService::BATCH_SIZE, $service->exportMaxFromRequest($default));
    }

    public function test_resolve_pairs_rejects_above_single_max(): void
    {
        $service = \Mockery::mock(StockLevelExportService::class, [app(\App\Services\InventoryStockService::class)])
            ->makePartial();

        $pairs = collect(range(1, StockLevelExportService::SINGLE_MAX + 1))->map(fn (int $i): array => [
            'item_id' => $i,
            'office_id' => 1,
            'unit_cost' => null,
        ]);

        $service->shouldReceive('collectPairs')->once()->andReturn($pairs);

        try {
            $service->resolvePairs(
                categoryId: null,
                search: null,
                restockFilter: 'active',
                scopedOfficeId: null,
                explicitPairKeys: [],
                maxPairs: StockLevelExportService::SINGLE_MAX,
            );
            $this->fail('Expected ValidationException for exports above SINGLE_MAX.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                (string) StockLevelExportService::SINGLE_MAX,
                collect($exception->errors())->flatten()->first() ?? '',
            );
        }
    }

    public function test_resolve_pairs_allows_elevated_max_under_single_max(): void
    {
        $service = \Mockery::mock(StockLevelExportService::class, [app(InventoryStockService::class)])
            ->makePartial();

        $count = StockLevelExportService::BATCH_SIZE + 1;
        $pairs = collect(range(1, $count))->map(fn (int $i): array => [
            'item_id' => $i,
            'office_id' => 1,
            'unit_cost' => null,
        ]);

        $service->shouldReceive('collectPairs')->once()->andReturn($pairs);

        $resolved = $service->resolvePairs(
            categoryId: null,
            search: null,
            restockFilter: 'active',
            scopedOfficeId: null,
            explicitPairKeys: [],
            maxPairs: StockLevelExportService::SINGLE_MAX,
        );

        $this->assertCount($count, $resolved);
    }

    public function test_light_export_positions_keep_distinct_unit_cost_pairs(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-COST',
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-C1'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 10,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);
        Acquisition::query()->create([
            'reference_code' => 'ACQ-C2'.$item->id,
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'unit_cost' => 20,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        $rows = app(InventoryStockService::class)->listExportStockPositions($category->id, $office->id);
        $pairs = app(StockLevelExportService::class)->collectPairs(
            categoryId: $category->id,
            search: null,
            restockFilter: 'active',
            scopedOfficeId: $office->id,
        );

        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertGreaterThanOrEqual(2, $pairs->count());
        $this->assertCount(
            $pairs->count(),
            $pairs->unique(fn (array $pair): string => $pair['item_id'].':'.$pair['office_id'].':'.($pair['unit_cost'] ?? ''))->values(),
        );
    }
}
