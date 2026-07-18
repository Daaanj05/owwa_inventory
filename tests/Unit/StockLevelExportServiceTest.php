<?php

namespace Tests\Unit;

use App\Services\StockLevelExportService;
use Tests\TestCase;

class StockLevelExportServiceTest extends TestCase
{
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
}
