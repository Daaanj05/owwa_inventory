<?php

namespace Tests\Feature;

use App\Filament\Widgets\LowStockWidget;
use App\Models\Acquisition;
use App\Models\AcquisitionPaperwork;
use App\Models\InspectionAcceptanceReport;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\AcquisitionUnitService;
use App\Support\DashboardKpiCache;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            ->assertSee('Orders and approvals')
            ->assertSee('Nothing awaiting action')
            ->assertDontSee('Orders to inspect & receive')
            ->assertDontSee('Pending requisitions')
            ->assertDontSee('Low stock');
    }

    public function test_supply_custodian_orders_and_approvals_modal_tabs_and_counts(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Pending PR',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            'pr_number' => 'PR-KPI-PENDING',
            'pr_submitted_at' => now(),
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

        $pendingPoPr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Pending PO paperwork',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'pr_number' => 'PR-KPI-FOR-PO',
        ]);
        PurchaseOrder::query()->create([
            'acquisition_paperwork_id' => $pendingPoPr->id,
            'status' => PurchaseOrder::STATUS_PENDING_APPROVAL,
            'number' => 'PO-KPI-PENDING',
            'po_date' => now(),
            'supplier_name' => 'Pending Supplier',
            'submitted_at' => now(),
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
            ->assertSee('Orders and approvals')
            ->assertSee('POs to receive, and PR or PO awaiting approval')
            ->assertSee('3')
            ->assertActionExists('viewOrdersToInspect')
            ->call('openOrdersAndApprovalsModal')
            ->assertActionMounted('viewOrdersToInspect')
            ->assertSet('kpiOrdersTab', 'pr');

        $prHtml = html_entity_decode((string) $component->instance()->getMountedAction()?->getModalContent());
        $this->assertStringContainsString('Pending PR approval', $prHtml);
        $this->assertStringContainsString('PO approval', $prHtml);
        $this->assertStringContainsString('Orders to inspect & receive', $prHtml);
        $this->assertStringContainsString('PR-KPI-PENDING', $prHtml);
        $this->assertStringContainsString('tableAction=view', $prHtml);
        $this->assertStringNotContainsString('PO-KPI-APPROVED', $prHtml);
        $this->assertStringNotContainsString('PO-KPI-DRAFT', $prHtml);

        $component->call('setKpiTab', 'orders', 'po');
        $poHtml = html_entity_decode((string) $component->instance()->getMountedAction()?->getModalContent());
        $this->assertStringContainsString('PO-KPI-PENDING', $poHtml);
        $this->assertStringContainsString('Pending Supplier', $poHtml);
        $this->assertStringContainsString('tableAction=view', $poHtml);
        $this->assertStringNotContainsString('PO-KPI-APPROVED', $poHtml);
        $this->assertStringNotContainsString('PO-KPI-DRAFT', $poHtml);
        $this->assertStringNotContainsString('PR-KPI-PENDING', $poHtml);

        $component->call('setKpiTab', 'orders', 'inspect');
        $inspectHtml = html_entity_decode((string) $component->instance()->getMountedAction()?->getModalContent());
        $this->assertStringContainsString('PO-KPI-APPROVED', $inspectHtml);
        $this->assertStringContainsString('Acme Supplies', $inspectHtml);
        $this->assertStringContainsString('tableAction=view', $inspectHtml);
        $this->assertStringNotContainsString('PO-KPI-DRAFT', $inspectHtml);
        $this->assertStringNotContainsString('PO-KPI-PENDING', $inspectHtml);
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
            ->assertSee('Orders and approvals')
            ->assertSee('POs to receive, and PR or PO awaiting approval')
            ->call('openOrdersAndApprovalsModal')
            ->assertActionMounted('viewOrdersToInspect')
            ->call('setKpiTab', 'orders', 'inspect');

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
        $this->assertStringContainsString('at or below reorder point', $html);
        $this->assertStringContainsString('Reorder point', $html);
        $this->assertStringNotContainsString('All categories', $html);
    }

    public function test_supply_custodian_useful_life_due_kpi_opens_modal_without_category_filter(): void
    {
        $office = Office::factory()->create();
        $semi = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);
        $holder = User::factory()->create([
            'name' => 'Useful Life Holder',
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $semi->id,
            'name' => 'Chair Past Life',
        ]);

        DB::table('issuances')->insert([
            'reference_code' => 'ISS-KPI-EUL',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 1,
            'issuance_date' => now()->subYears(4)->toDateString(),
            'estimated_useful_life' => '36 months',
            'eul_expires_at' => now()->subDay()->toDateString(),
            'property_number' => 'SEMI-KPI-1',
            'issued_to' => $holder->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);

        $component = Livewire::test(LowStockWidget::class)
            ->assertOk()
            ->assertSee('Useful Life Due')
            ->assertSee('Nearing or past useful life')
            ->mountAction('viewUsefulLifeDue')
            ->assertActionMounted('viewUsefulLifeDue');

        $html = (string) $component->instance()->getMountedAction()?->getModalContent();
        $this->assertStringContainsString('Chair Past Life', $html);
        $this->assertStringContainsString('SEMI-KPI-1', $html);
        $this->assertStringContainsString('Useful Life Holder', $html);
        $this->assertStringContainsString('Expired', $html);
        $this->assertStringNotContainsString('All categories', $html);
        $this->assertStringNotContainsString('Reorder point', $html);

        $openPageUrl = (string) $component->instance()->getMountedAction()
            ?->getModalFooterActions()['openPage']
            ?->getUrl();
        $this->assertStringContainsString('analyticsSlide=replacement', html_entity_decode($openPageUrl));
    }

    public function test_supply_custodian_pending_kpi_uses_sql_counts_matching_modal_rows(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);
        $consolidator = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $older = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $consolidator->id,
            'status' => Requisition::STATUS_PENDING,
            'reference_code' => 'REQ-OLDER-KPI',
            'transaction_number' => '2026-01-0000',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $older->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'quantity_issued' => 0,
        ]);

        $pending = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $consolidator->id,
            'status' => Requisition::STATUS_PENDING,
            'reference_code' => 'REQ-PENDING-KPI',
            'transaction_number' => '2026-01-0001',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $pending->id,
            'item_id' => $item->id,
            'quantity' => 5,
            'quantity_issued' => 0,
        ]);

        $remainder = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $consolidator->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'reference_code' => 'REQ-REMAINDER-KPI',
            'transaction_number' => '2026-01-0002',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $remainder->id,
            'item_id' => $item->id,
            'quantity' => 8,
            'quantity_issued' => 3,
        ]);

        DB::table('requisitions')->where('id', $older->id)->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        DB::table('requisitions')->where('id', $pending->id)->update([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('requisitions')->where('id', $remainder->id)->update([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        DashboardKpiCache::bump();

        $fullyIssued = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $consolidator->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'reference_code' => 'REQ-FULL-KPI',
            'transaction_number' => '2026-01-0003',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $fullyIssued->id,
            'item_id' => $item->id,
            'quantity' => 4,
            'quantity_issued' => 4,
        ]);

        PropertyActionRequest::query()->create([
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'reason_code' => 'serviceable_not_needed',
            'requested_by' => $consolidator->id,
            'accountable_user_id' => $consolidator->id,
            'office_id' => $office->id,
            'status' => PropertyActionRequest::STATUS_PENDING_SC,
            'reference_code' => 'PAR-PENDING-KPI',
        ]);

        $this->actingAs($custodian);

        $pendingCacheKey = 'dashboard.kpi_counts.v'.DashboardKpiCache::version().'.supply_custodian.pending_modal_rows';
        $this->assertFalse(Cache::has($pendingCacheKey));

        $component = Livewire::test(LowStockWidget::class)
            ->assertOk()
            ->assertSee('Pending requests & returns')
            ->assertSee('4')
            ->call('openPendingRequisitionsModal')
            ->assertActionMounted('viewPendingRequisitions')
            ->assertSet('kpiPendingTab', 'requisition');

        $this->assertTrue(Cache::has($pendingCacheKey));

        /** @var array{requisition: array<int, array{reference: string}>} $cachedPendingRows */
        $cachedPendingRows = Cache::get($pendingCacheKey);
        $this->assertSame(
            ['REQ-PENDING-KPI', 'REQ-REMAINDER-KPI', 'REQ-OLDER-KPI'],
            array_column($cachedPendingRows['requisition'], 'reference'),
        );

        $html = html_entity_decode((string) $component->instance()->getMountedAction()?->getModalContent());
        $this->assertStringContainsString('4 pending requests & returns', $html);
        $this->assertStringContainsString('Pending Requisition', $html);
        $this->assertStringContainsString('Pending Property Return', $html);
        $this->assertStringContainsString('REQ-PENDING-KPI', $html);
        $this->assertStringContainsString('REQ-REMAINDER-KPI', $html);
        $this->assertStringContainsString('REQ-OLDER-KPI', $html);
        $this->assertStringContainsString('tableAction=view', $html);
        $this->assertStringNotContainsString('PAR-PENDING-KPI', $html);
        $this->assertStringNotContainsString('REQ-FULL-KPI', $html);

        $component->call('setKpiTab', 'pending', 'return');
        $returnHtml = html_entity_decode((string) $component->instance()->getMountedAction()?->getModalContent());
        $this->assertStringContainsString('PAR-PENDING-KPI', $returnHtml);
        $this->assertStringContainsString('tableAction=view', $returnHtml);
        $this->assertStringNotContainsString('REQ-PENDING-KPI', $returnHtml);
        $this->assertStringNotContainsString('REQ-REMAINDER-KPI', $returnHtml);

        $component
            ->call('unmountAction')
            ->call('openPendingRequisitionsModal')
            ->assertSet('kpiPendingTab', 'requisition');

        $reopenedHtml = html_entity_decode((string) $component->instance()->getMountedAction()?->getModalContent());
        $this->assertStringContainsString('REQ-PENDING-KPI', $reopenedHtml);
        $this->assertStringNotContainsString('PAR-PENDING-KPI', $reopenedHtml);
        $this->assertTrue(Cache::has($pendingCacheKey));
    }

    public function test_pending_kpi_cache_refreshes_when_requisition_is_created(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);
        $consolidator = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($custodian);

        Livewire::test(LowStockWidget::class)
            ->assertOk()
            ->assertSee('Nothing awaiting action');

        $versionBefore = DashboardKpiCache::version();

        $pending = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $consolidator->id,
            'status' => Requisition::STATUS_PENDING,
            'reference_code' => 'REQ-CACHE-NEW',
            'transaction_number' => '2026-02-0001',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $pending->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'quantity_issued' => 0,
        ]);

        $this->assertGreaterThan($versionBefore, DashboardKpiCache::version());

        Livewire::test(LowStockWidget::class)
            ->assertOk()
            ->assertSee('Pending requests & returns')
            ->assertSee('1')
            ->assertSee('Requests & returns awaiting your action');
    }

    public function test_orders_modal_rows_are_cached_and_reset_to_pr_tab_on_reopen(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
            'email_verified_at' => now(),
        ]);

        AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Pending PR',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
            'pr_number' => 'PR-KPI-CACHE',
            'pr_submitted_at' => now(),
        ]);

        $pendingPoPr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Pending PO paperwork',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'pr_number' => 'PR-KPI-FOR-PO-CACHE',
        ]);
        PurchaseOrder::query()->create([
            'acquisition_paperwork_id' => $pendingPoPr->id,
            'status' => PurchaseOrder::STATUS_PENDING_APPROVAL,
            'number' => 'PO-KPI-CACHE',
            'po_date' => now(),
            'supplier_name' => 'Pending Supplier',
            'submitted_at' => now(),
        ]);

        $this->actingAs($user);

        $ordersCacheKey = 'dashboard.kpi_counts.v'.DashboardKpiCache::version().'.supply_custodian.orders_modal_rows';
        $this->assertFalse(Cache::has($ordersCacheKey));

        $component = Livewire::test(LowStockWidget::class)
            ->call('openOrdersAndApprovalsModal')
            ->assertActionMounted('viewOrdersToInspect')
            ->assertSet('kpiOrdersTab', 'pr');

        $this->assertTrue(Cache::has($ordersCacheKey));
        $this->assertStringContainsString(
            'PR-KPI-CACHE',
            html_entity_decode((string) $component->instance()->getMountedAction()?->getModalContent()),
        );

        $component
            ->call('setKpiTab', 'orders', 'po')
            ->assertSet('kpiOrdersTab', 'po')
            ->call('unmountAction')
            ->call('openOrdersAndApprovalsModal')
            ->assertSet('kpiOrdersTab', 'pr');

        $reopenedHtml = html_entity_decode((string) $component->instance()->getMountedAction()?->getModalContent());
        $this->assertStringContainsString('PR-KPI-CACHE', $reopenedHtml);
        $this->assertStringNotContainsString('PO-KPI-CACHE', $reopenedHtml);
        $this->assertTrue(Cache::has($ordersCacheKey));
    }
}
