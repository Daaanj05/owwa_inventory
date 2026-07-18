<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\StockPositionRestockFlag;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\StockPositionInactivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarkStaleZeroStockPositionsInactiveCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_marks_year_old_zero_stock_positions_inactive_automatically(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17', config('app.timezone')));

        [$item, $office] = $this->seedZeroStockPosition(
            acquiredOn: '2024-01-15',
            depletedOn: '2025-06-01',
        );

        Artisan::call('inventory:mark-stale-zero-stock-inactive');

        $flag = StockPositionRestockFlag::findForPosition($item->id, $office->id, 25.00);

        $this->assertNotNull($flag);
        $this->assertTrue($flag->is_inactive_for_restock);
        $this->assertSame(StockPositionRestockFlag::SOURCE_AUTOMATIC, $flag->inactive_source);
        $this->assertSame('No stock for 1 year', $flag->inactive_note);
        $this->assertSame('2025-06-01', $flag->zero_stock_since?->toDateString());
        $this->assertSame('Inactive — no stock for 1 year', $flag->statusLabel());
    }

    public function test_command_does_not_overwrite_manual_inactive_source(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17', config('app.timezone')));

        [$item, $office, $user] = $this->seedZeroStockPosition(
            acquiredOn: '2024-01-15',
            depletedOn: '2025-06-01',
            withUser: true,
        );

        StockPositionRestockFlag::markInactive($item->id, $office->id, 25.00, $user->id, 'Manual hold');

        Artisan::call('inventory:mark-stale-zero-stock-inactive');

        $flag = StockPositionRestockFlag::findForPosition($item->id, $office->id, 25.00);

        $this->assertTrue($flag->is_inactive_for_restock);
        $this->assertSame(StockPositionRestockFlag::SOURCE_MANUAL, $flag->inactive_source);
        $this->assertSame('Manual hold', $flag->inactive_note);
        $this->assertSame('Inactive', $flag->statusLabel());
    }

    public function test_acquisition_reactivates_inactive_position_and_clears_aging(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17', config('app.timezone')));

        [$item, $office] = $this->seedZeroStockPosition(
            acquiredOn: '2024-01-15',
            depletedOn: '2025-06-01',
        );

        StockPositionRestockFlag::markAutomaticallyInactive(
            $item->id,
            $office->id,
            25.00,
            Carbon::parse('2025-06-01'),
        );

        Acquisition::query()->create([
            'reference_code' => 'ACQ-REACTIVATE-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 3,
            'unit_cost' => 25.00,
            'acquisition_date' => '2026-07-17',
            'source' => 'Restock',
            'recorded_by' => User::factory()->create(['office_id' => $office->id])->id,
        ]);

        $flag = StockPositionRestockFlag::findForPosition($item->id, $office->id, 25.00);

        $this->assertNotNull($flag);
        $this->assertFalse($flag->is_inactive_for_restock);
        $this->assertNull($flag->inactive_source);
        $this->assertNull($flag->zero_stock_since);
        $this->assertGreaterThan(0, app(InventoryStockService::class)->getStockForUnitCost($item->id, $office->id, 25.00));
    }

    public function test_soft_deleted_movements_are_ignored_by_stock_maps(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create(['office_id' => $office->id]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-SOFT-DEL-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'unit_cost' => 25.00,
            'acquisition_date' => '2026-01-01',
            'source' => 'Demo',
            'recorded_by' => $user->id,
        ]);

        $this->assertSame(
            5,
            app(InventoryStockService::class)->getStockForUnitCost($item->id, $office->id, 25.00),
        );

        $acquisition->delete();

        $this->assertSame(
            0,
            app(InventoryStockService::class)->getStockForUnitCost($item->id, $office->id, 25.00),
        );
    }

    public function test_resolve_zero_stock_since_tracks_continuous_zero_interval(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17', config('app.timezone')));

        [$item, $office] = $this->seedZeroStockPosition(
            acquiredOn: '2024-01-15',
            depletedOn: '2025-06-01',
        );

        $zeroSince = app(StockPositionInactivityService::class)
            ->resolveZeroStockSince($item->id, $office->id, 25.00);

        $this->assertSame('2025-06-01', $zeroSince?->toDateString());
    }

    /**
     * @return array{0: Item, 1: Office, 2?: User}
     */
    protected function seedZeroStockPosition(
        string $acquiredOn,
        string $depletedOn,
        bool $withUser = false,
    ): array {
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $office = Office::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Demo Zero Stock Item',
        ]);
        $user = User::factory()->create(['office_id' => $office->id]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-ZERO-'.uniqid(),
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'unit_cost' => 25.00,
            'acquisition_date' => $acquiredOn,
            'source' => 'Demo',
            'recorded_by' => $user->id,
        ]);

        $now = now();
        DB::table('disposals')->insert([
            'reference_code' => 'DSP-ZERO-'.uniqid(),
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'acquisition_cost' => 25.00,
            'disposal_date' => $depletedOn,
            'reason' => 'Demo depletion',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertSame(
            0,
            app(InventoryStockService::class)->getStockForUnitCost($item->id, $office->id, 25.00),
        );

        return $withUser ? [$item, $office, $user] : [$item, $office];
    }
}
