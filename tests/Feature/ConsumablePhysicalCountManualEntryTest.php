<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PhysicalCountLine;
use App\Models\PhysicalCountSession;
use App\Models\User;
use App\Services\PhysicalCountPreloadService;
use App\Services\PhysicalCountScanService;
use App\Support\PhysicalCountScanOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsumablePhysicalCountManualEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_rpci_sessions_do_not_support_qr_scanning(): void
    {
        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => Office::factory()->create()->id,
            'item_category_id' => ItemCategory::factory()->create(['name' => 'Consumables'])->id,
            'count_date' => now(),
            'recorded_by' => User::factory()->create()->id,
        ]);

        $this->assertTrue($session->isConsumablePhysicalCount());
        $this->assertFalse($session->supportsQrScanning());
        $this->assertFalse($session->supportsUnitQrScanning());
        $this->assertTrue($session->supportsCompletionWorkflow());
    }

    public function test_preload_stock_lines_then_manual_on_hand_updates_variance(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Bond Paper A4',
            'item_code' => 'CONS-001',
            'unit' => 'ream',
        ]);

        $this->createAcquisition($item->id, $office->id, 20);

        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCI,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'recorded_by' => User::factory()->create()->id,
        ]);

        $result = app(PhysicalCountPreloadService::class)->preloadFromStockBalances($session);

        $this->assertSame(1, $result['created']);
        $this->assertTrue($session->fresh()->hasBookListLoaded());

        $line = PhysicalCountLine::query()
            ->where('physical_count_session_id', $session->id)
            ->where('item_id', $item->id)
            ->first();

        $this->assertNotNull($line);
        $this->assertSame(20, (int) $line->balance_per_card);
        $this->assertSame(0, (int) $line->on_hand_count);
        $this->assertSame(-20, $line->shortageOverageQuantity());

        $line->update(['on_hand_count' => 18]);

        $summary = $session->fresh(['lines'])->countSummary();
        $this->assertSame(20, $summary['expected']);
        $this->assertSame(18, $summary['scanned']);
        $this->assertSame(1, $summary['shortages']);
        $this->assertSame(0, $summary['matched']);
        $this->assertFalse($summary['scan_only']);
    }

    public function test_unit_scan_rejects_retired_stock_qr_payload(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'PPE']);
        $session = PhysicalCountSession::query()->create([
            'count_type' => PhysicalCountSession::TYPE_RPCPPE,
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'count_date' => now(),
            'recorded_by' => User::factory()->create()->id,
            'book_list_loaded' => true,
        ]);

        $result = app(PhysicalCountScanService::class)->resolve(
            $session,
            'OWWA|1|kind=stock|item=9|office='.$office->id,
        );

        $this->assertSame(PhysicalCountScanOutcome::NotFound, $result->outcome);
        $this->assertStringContainsString('no longer used', (string) $result->message);
    }

    public function test_stock_qr_routes_are_removed(): void
    {
        $this->assertFalse(
            collect(app('router')->getRoutes())->contains(
                fn ($route): bool => in_array($route->getName(), [
                    'inventory.stock.show',
                    'owwa.qr-labels.stock',
                ], true),
            ),
        );
    }

    protected function createAcquisition(int $itemId, int $officeId, int $quantity): void
    {
        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-PC-'.$itemId.'-'.$officeId.'-'.uniqid(),
            'item_id' => $itemId,
            'office_id' => $officeId,
            'quantity' => $quantity,
            'unit_cost' => 100,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
