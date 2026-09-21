<?php

namespace Tests\Feature;

use App\Filament\Widgets\LowStockWidget;
use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\InspectionAcceptanceReport;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PurchaseOrder;
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
            ->assertDontSee('Items in total')
            ->assertDontSee('Stocks in hand')
            ->assertSee('Items running low')
            ->assertSee('Pending requests & returns')
            ->assertSee('Orders to inspect & receive')
            ->assertSee('Approved POs still waiting to be received')
            ->assertDontSee('Pending requisitions')
            ->assertDontSee('Low stock');
    }

    public function test_supply_custodian_orders_to_inspect_modal_shows_approved_po_only(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $approvedPr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Approved order',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'pr_number' => 'PR-KPI-APPROVED',
        ]);
        PurchaseOrder::query()->create([
            'acquisition_paperwork_id' => $approvedPr->id,
            'status' => PurchaseOrder::STATUS_APPROVED,
            'number' => 'PO-KPI-APPROVED',
            'po_date' => now(),
            'supplier_name' => 'Acme Supplies',
            'approved_at' => now(),
        ]);

        $draftPr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Draft order',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'pr_number' => 'PR-KPI-DRAFT',
        ]);
        PurchaseOrder::query()->create([
            'acquisition_paperwork_id' => $draftPr->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'number' => 'PO-KPI-DRAFT',
            'po_date' => now(),
            'supplier_name' => 'Draft Supplier',
        ]);

        $this->actingAs($user);

        $component = Livewire::test(LowStockWidget::class)
            ->assertOk()
            ->assertSee('Orders to inspect & receive')
            ->assertSee('Approved POs still waiting to be received')
            ->assertActionExists('viewOrdersToInspect')
            ->mountAction('viewOrdersToInspect')
            ->assertActionMounted('viewOrdersToInspect');

        $html = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('PO-KPI-APPROVED', $html);
        $this->assertStringContainsString('Acme Supplies', $html);
        $this->assertStringNotContainsString('PO-KPI-DRAFT', $html);
    }

    public function test_supply_custodian_orders_to_inspect_includes_iar_until_received(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $withIarPr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'With IAR',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'pr_number' => 'PR-KPI-IAR',
        ]);
        $withIarPo = PurchaseOrder::query()->create([
            'acquisition_paperwork_id' => $withIarPr->id,
            'status' => PurchaseOrder::STATUS_APPROVED,
            'number' => 'PO-KPI-WITH-IAR',
            'po_date' => now(),
            'supplier_name' => 'IAR Supplier',
            'approved_at' => now(),
        ]);
        InspectionAcceptanceReport::query()->create([
            'purchase_order_id' => $withIarPo->id,
            'status' => InspectionAcceptanceReport::STATUS_APPROVED,
            'iar_date' => now(),
            'approved_at' => now(),
        ]);

        $receivedPr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Already received',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'pr_number' => 'PR-KPI-RECEIVED',
            'received_at' => now(),
        ]);
        $receivedPo = PurchaseOrder::query()->create([
            'acquisition_paperwork_id' => $receivedPr->id,
            'status' => PurchaseOrder::STATUS_APPROVED,
            'number' => 'PO-KPI-RECEIVED',
            'po_date' => now(),
            'supplier_name' => 'Received Supplier',
            'approved_at' => now(),
        ]);
        InspectionAcceptanceReport::query()->create([
            'purchase_order_id' => $receivedPo->id,
            'status' => InspectionAcceptanceReport::STATUS_APPROVED,
            'iar_date' => now(),
            'approved_at' => now(),
            'stock_received_at' => now(),
        ]);

        $this->actingAs($user);

        $component = Livewire::test(LowStockWidget::class)
            ->assertOk()
            ->assertSee('Approved POs still waiting to be received')
            ->mountAction('viewOrdersToInspect')
            ->assertActionMounted('viewOrdersToInspect');

        $html = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('PO-KPI-WITH-IAR', $html);
        $this->assertStringNotContainsString('PO-KPI-RECEIVED', $html);
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
            ->assertSee('Items running low')
            ->assertSee('1')
            ->mountAction('viewLowStock')
            ->assertActionMounted('viewLowStock');

        $html = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('Regional Low Item', $html);
        $this->assertStringNotContainsString('Satellite Low Item', $html);
        $this->assertStringContainsString('1 low-stock item', $html);
    }
}
