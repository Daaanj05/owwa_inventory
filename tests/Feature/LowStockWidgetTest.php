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
            'reorder_level' => 5,
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
            ->assertDontSee('Stocks in hand')
            ->assertSee('Low stock')
            ->assertSee('Pending requisitions')
            ->assertSee('2');
    }

    public function test_supply_custodian_can_open_items_kpi_modal(): void
    {
        $office = Office::factory()->create();
        $consumables = ItemCategory::factory()->create(['name' => 'Consumable Supplies']);
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        Item::factory()->create([
            'item_category_id' => $consumables->id,
            'name' => 'Bond Paper A4',
            'item_code' => 'CON-KPI-OPEN',
        ]);
        Item::factory()->create([
            'item_category_id' => $semi->id,
            'name' => 'Office Chair Semi',
            'item_code' => 'SE-KPI-OPEN',
            'semi_expendable_property_number' => 'TEMP-2026-FF-106-0001-IVA',
        ]);

        $this->actingAs($user);

        $component = Livewire::test(LowStockWidget::class)
            ->assertOk()
            ->assertActionExists('viewItemsInTotal')
            ->mountAction('viewItemsInTotal')
            ->assertActionMounted('viewItemsInTotal');

        $html = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('Bond Paper A4', $html);
        $this->assertStringContainsString('All categories', $html);
        $this->assertStringContainsString('Office Chair Semi', $html);

        $component->call('setKpiCategory', 'items', (string) $semi->id);
        $filteredHtml = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('Office Chair Semi', $filteredHtml);
        $this->assertStringNotContainsString('Bond Paper A4', $filteredHtml);
    }

    public function test_supply_custodian_low_stock_kpi_matches_modal_for_regional_office_only(): void
    {
        $regional = Office::factory()->create(['name' => 'Regional Office']);
        $satellite = Office::factory()->create(['name' => 'Satellite Office']);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $regional->id,
            'email_verified_at' => now(),
        ]);

        $regionalItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Regional Low Item',
            'item_code' => 'CON-LOW-RO',
            'reorder_level' => 10,
        ]);
        $satelliteItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Satellite Low Item',
            'item_code' => 'CON-LOW-SAT',
            'reorder_level' => 10,
        ]);

        foreach ([
            [$regionalItem, $regional, 2],
            [$satelliteItem, $satellite, 3],
        ] as [$item, $office, $qty]) {
            $acquisition = Acquisition::query()->create([
                'reference_code' => 'ACQ-LOW-'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => $qty,
                'unit_cost' => 25,
                'acquisition_date' => now(),
                'recorded_by' => $user->id,
            ]);
            app(AcquisitionUnitService::class)->generateUnitsForAcquisition($acquisition);
        }

        $this->actingAs($user);

        $component = Livewire::test(LowStockWidget::class)
            ->assertOk()
            ->assertSee('1')
            ->mountAction('viewLowStock')
            ->assertActionMounted('viewLowStock');

        $html = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('Regional Low Item', $html);
        $this->assertStringNotContainsString('Satellite Low Item', $html);
        $this->assertStringContainsString('1 low-stock item', $html);
    }
}
