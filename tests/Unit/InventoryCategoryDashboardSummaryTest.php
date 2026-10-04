<?php

namespace Tests\Unit;

use App\Filament\Pages\InventoryCategoryDashboard;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Services\InventoryStockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryCategoryDashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_dashboard_summary_includes_total_stock_quantity(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $otherCategory = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'SEM-ICD-1',
        ]);
        $otherCategoryItem = Item::factory()->create([
            'item_category_id' => $otherCategory->id,
            'item_code' => 'CON-ICD-1',
        ]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-ICD-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 4,
            'unit_cost' => 100,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-ICD-OTHER-OFFICE',
            'item_id' => $item->id,
            'office_id' => $otherOffice->id,
            'quantity' => 10,
            'unit_cost' => 100,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-ICD-OTHER-CAT',
            'item_id' => $otherCategoryItem->id,
            'office_id' => $office->id,
            'quantity' => 7,
            'unit_cost' => 50,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        $this->actingAs($user);
        session()->put('active_item_category_id', $category->id);

        $component = Livewire::test(InventoryCategoryDashboard::class, ['category' => $category->id]);
        $summary = $component->instance()->getStockSummary();

        $this->assertSame(4, $summary['totalStockQty']);
        $this->assertSame(1, $summary['total']);
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
