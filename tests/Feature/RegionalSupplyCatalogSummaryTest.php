<?php

namespace Tests\Feature;

use App\Filament\Pages\RegionalSupplyCatalog;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\InventoryStockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegionalSupplyCatalogSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_catalog_shows_one_row_per_item_across_cost_lots(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create([
            'name' => 'MESH CHAIR',
            'item_category_id' => $category->id,
            'reorder_level' => 0,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        Acquisition::query()->create([
            'reference_code' => 'ACQ-CAT-10',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 10,
            'unit_cost' => 100.00,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);
        Acquisition::query()->create([
            'reference_code' => 'ACQ-CAT-5',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 5,
            'unit_cost' => 150.00,
            'acquisition_date' => now(),
            'recorded_by' => $custodian->id,
        ]);

        app(InventoryStockService::class)->forgetMovementTotalsCache();

        $this->assertCount(2, app(InventoryStockService::class)->getStockLevelsList(null, $office->id));

        $component = Livewire::actingAs($employee)
            ->test(RegionalSupplyCatalog::class)
            ->assertSee('MESH CHAIR')
            ->assertSee('15');

        $summary = $component->instance()->getStockSummary();
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['okCount']);
        $this->assertSame(1, substr_count($component->html(), '>MESH CHAIR<'));
    }
}
