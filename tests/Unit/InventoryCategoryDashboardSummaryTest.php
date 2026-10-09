<?php

namespace Tests\Unit;

use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\InventoryStockService;
use App\Support\InventoryCategoryTasks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryCategoryDashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_tasks_include_transfers_only_outside_consumables(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $consumables = ItemCategory::factory()->create(['name' => 'Consumables']);

        $semiTitles = array_column(InventoryCategoryTasks::forCategory($semi), 'title');
        $consumableTitles = array_column(InventoryCategoryTasks::forCategory($consumables), 'title');

        $this->assertContains('Transfers', $semiTitles);
        $this->assertContains('Inventory Schedule', $semiTitles);
        $this->assertNotContains('Transfers', $consumableTitles);
        $this->assertContains('Stock levels', $consumableTitles);
    }

    public function test_category_dashboard_summary_uses_cache_until_stock_cache_is_forgotten(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-ICD-CACHE-1',
        ]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-ICD-CACHE-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'unit_cost' => 20,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);
        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);

        $this->actingAs($user);
        session()->put('active_item_category_id', $category->id);

        $stockService = app(InventoryStockService::class);
        $first = $stockService->summarizeCategoryOfficeStock($category->id, $office->id);
        $this->assertSame(5, $first['totalStockQty']);

        Acquisition::withoutEvents(function () use ($item, $office, $user): void {
            Acquisition::query()->create([
                'reference_code' => 'ACQ-ICD-CACHE-HIDDEN',
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 7,
                'unit_cost' => 20,
                'acquisition_date' => now(),
                'recorded_by' => $user->id,
            ]);
        });

        $cached = $stockService->summarizeCategoryOfficeStock($category->id, $office->id);
        $this->assertSame(5, $cached['totalStockQty']);

        $stockService->forgetMovementTotalsCache();

        $fresh = $stockService->summarizeCategoryOfficeStock($category->id, $office->id);
        $this->assertSame(12, $fresh['totalStockQty']);
    }
}
