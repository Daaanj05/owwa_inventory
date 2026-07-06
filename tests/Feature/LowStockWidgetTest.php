<?php

namespace Tests\Feature;

use App\Filament\Widgets\LowStockWidget;
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

class LowStockWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_supply_custodian_sees_inventory_kpis_with_correct_counts(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumable']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $itemWithStock = Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-001',
        ]);
        Item::factory()->create([
            'item_category_id' => $category->id,
            'item_code' => 'CON-002',
        ]);

        $acquisition = Acquisition::query()->create([
            'reference_code' => 'ACQ-KPI-1',
            'item_id' => $itemWithStock->id,
            'office_id' => $office->id,
            'quantity' => 12,
            'unit_cost' => 50,
            'acquisition_date' => now(),
            'recorded_by' => $user->id,
        ]);

        app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);

        $this->actingAs($user);

        Livewire::test(LowStockWidget::class)
            ->assertOk()
            ->assertSee('Items in total')
            ->assertSee('Stocks in hand')
            ->assertSee('Low stock')
            ->assertSee('Pending requisitions')
            ->assertSee('2')
            ->assertSee('12');
    }
}
