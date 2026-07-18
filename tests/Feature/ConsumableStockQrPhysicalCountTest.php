<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Services\ConsumablePhysicalCountScanService;
use App\Services\InventoryQrLabelService;
use App\Services\PhysicalCountScanService;
use App\Support\ConsumableInventoryType;
use App\Support\ConsumableStockQrPayload;
use App\Support\PhysicalCountScanOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumableStockQrPhysicalCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_qr_payload_round_trip_legacy(): void
    {
        config(['inventory.qr_public_lookup' => false]);

        $item = Item::factory()->create(['item_code' => 'CON-100']);
        $office = Office::factory()->create();

        $encoded = ConsumableStockQrPayload::encodeLegacy($item, $office);
        $parsed = ConsumableStockQrPayload::parse($encoded);

        $this->assertNotNull($parsed);
        $this->assertSame($item->id, $parsed->itemId);
        $this->assertSame($office->id, $parsed->officeId);
        $this->assertSame('CON-100', $parsed->stockNumber);
    }

    public function test_rpci_scan_sets_quantity_and_rejects_property_tag(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
            'item_code' => 'CON-200',
        ]);
        $user = User::factory()->create();

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'inventory_type' => ConsumableInventoryType::OfficeSupplies,
            'inventory_type_label' => ConsumableInventoryType::label(ConsumableInventoryType::OfficeSupplies),
            'accountable_officer_name' => 'Officer',
            'recorded_by' => $user->id,
        ]);

        config(['inventory.qr_public_lookup' => false]);
        $payload = ConsumableStockQrPayload::encodeLegacy($item, $office);

        $scanner = app(ConsumablePhysicalCountScanService::class);
        $pending = $scanner->resolve($session, $payload, $user->id);

        $this->assertTrue($pending->needsQuantity());
        $this->assertNotNull($pending->line);

        $saved = $scanner->applyQuantity($session, $pending->line, 7, $user->id);
        $this->assertSame(PhysicalCountScanOutcome::Found, $saved->outcome);
        $this->assertSame(7, (int) $pending->line->fresh()->on_hand_count);

        $rejected = $scanner->resolve($session, 'OWWA|1|pn=PPE-999|item=1|office='.$office->id, $user->id);
        $this->assertSame(PhysicalCountScanOutcome::NotFound, $rejected->outcome);
    }

    public function test_unit_scan_rejects_stock_qr(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'accountable_officer_name' => 'Officer',
            'recorded_by' => User::factory()->create()->id,
        ]);

        $item = Item::factory()->create(['item_code' => 'CON-300']);
        config(['inventory.qr_public_lookup' => false]);
        $stockPayload = ConsumableStockQrPayload::encodeLegacy($item, $office);

        $result = app(PhysicalCountScanService::class)->resolve($session, $stockPayload, null);

        $this->assertSame(PhysicalCountScanOutcome::NotFound, $result->outcome);
        $this->assertStringContainsString('stock', strtolower((string) $result->message));
    }

    public function test_stock_qr_label_service_returns_png_data_uri(): void
    {
        $item = Item::factory()->create(['item_code' => 'CON-400', 'name' => 'Bond Paper']);
        $office = Office::factory()->create(['name' => 'Regional Office']);

        $labels = app(InventoryQrLabelService::class)->labelsForConsumableStock($item, $office);

        $this->assertCount(1, $labels);
        $this->assertStringStartsWith('data:image/png;base64,', $labels->first()['qr_data_uri']);
        $this->assertSame('CON-400', $labels->first()['property_number']);
    }
}
