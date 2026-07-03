<?php

namespace Tests\Unit;

use App\Filament\Pages\InventoryCategoryDashboard;
use App\Models\Acquisition;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionUnitService;
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
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'SEM-ICD-1',
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

        $this->actingAs($user);
        session()->put('active_item_category_id', $category->id);

        $component = Livewire::test(InventoryCategoryDashboard::class, ['category' => $category->id]);
        $summary = $component->instance()->getStockSummary();

        $this->assertSame(4, $summary['totalStockQty']);
        $this->assertSame(1, $summary['total']);
    }
}
