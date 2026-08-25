<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Filament\Resources\Acquisitions\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Models\AcquisitionPaperwork;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\PurchaseOrderWorkflowService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AcquisitionDocumentTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquisitions_list_shows_pr_tabs_and_new_pr_action(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ListAcquisitions::class)
            ->assertSee('New PR')
            ->assertSee('Active')
            ->assertSee('Archived')
            ->assertSeeHtml('owwa-acquisition-doc-tabs')
            ->assertSeeHtml('owwa-acquisition-doc-tab is-active')
            ->assertSee('Received');
    }

    public function test_create_po_from_approved_pr_preserves_pr_quantities(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = \App\Models\Item::factory()->create(['item_category_id' => $category->id]);
        $user = User::factory()->create();

        $pr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Office supplies',
            'pr_date' => now(),
        ]);

        $pr->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'ream',
            'quantity' => 10,
        ]);

        $completion = app(AcquisitionPaperworkCompletionService::class);
        $completion->submitPr($pr->fresh());
        $completion->approvePr($pr->fresh());

        $po = app(PurchaseOrderWorkflowService::class)->createFromApprovedPr($pr->fresh());

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertSame(10, (int) $po->lines->first()->pr_quantity);
        $this->assertTrue($po->lines->first()->is_ordered);
        $this->assertSame(10, (int) $pr->fresh()->lines->first()->quantity);

        $po->lines()->update([
            'is_ordered' => true,
            'po_quantity' => 6,
            'unit_cost' => 12.5,
            'amount' => 75,
        ]);

        $this->assertSame(10, (int) $pr->fresh()->lines->first()->quantity);
        $this->assertSame(6, (int) $po->fresh()->lines->first()->po_quantity);
    }

    public function test_pending_pr_is_view_only_and_can_be_archived(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $user = User::factory()->create();
        $pr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $user->id,
            'purpose' => 'Office supplies',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_PENDING_APPROVAL,
        ]);

        $this->assertFalse($pr->isPrEditable());

        app(AcquisitionPaperworkCompletionService::class)->archive($pr);
        $this->assertTrue($pr->fresh()->isArchived());
    }

    public function test_purchase_orders_list_page_loads(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        Livewire::actingAs($custodian)
            ->test(ListPurchaseOrders::class)
            ->assertSee('Create PO')
            ->assertSee('Active');
    }

    public function test_document_tab_urls_are_not_nested_under_acquisitions_record(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $poUrl = \App\Filament\Resources\Acquisitions\PurchaseOrders\PurchaseOrderResource::getUrl('index', ['category' => $category->id]);
        $iarUrl = \App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\InspectionAcceptanceReportResource::getUrl('index', ['category' => $category->id]);
        $receivedUrl = \App\Filament\Resources\Acquisitions\AcquisitionResource::getUrl('received', ['category' => $category->id]);

        $this->assertStringContainsString('/purchase-orders', $poUrl);
        $this->assertStringContainsString('/inspection-acceptance-reports', $iarUrl);
        $this->assertStringContainsString('/acquisitions/received', $receivedUrl);
        $this->assertStringNotContainsString('/acquisitions/', $poUrl);
        $this->assertStringNotContainsString('/acquisitions/', $iarUrl);

        Livewire::actingAs($custodian)
            ->test(ListAcquisitions::class, ['category' => $category->id])
            ->assertSeeHtml(e($poUrl))
            ->assertSeeHtml(e($iarUrl))
            ->assertSeeHtml(e($receivedUrl));
    }

    public function test_received_tab_lists_received_cases_and_keeps_them_on_pr_active(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $activePr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $custodian->id,
            'purpose' => 'Active PR case',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
            'pr_number' => 'PR-ACTIVE-1',
        ]);

        $receivedPr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $custodian->id,
            'purpose' => 'Received PR case',
            'pr_date' => now()->subDays(5),
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'pr_number' => 'PR-RECEIVED-1',
            'po_number' => 'PO-RECEIVED-1',
            'iar_number' => 'IAR-RECEIVED-1',
            'received_at' => now(),
        ]);

        Livewire::actingAs($custodian)
            ->test(ListAcquisitions::class, ['category' => $category->id])
            ->assertCanSeeTableRecords([$activePr, $receivedPr]);

        Livewire::actingAs($custodian)
            ->test(\App\Filament\Resources\Acquisitions\Pages\ListReceivedAcquisitions::class, ['category' => $category->id])
            ->assertSee('Received')
            ->assertCanSeeTableRecords([$receivedPr])
            ->assertCanNotSeeTableRecords([$activePr]);
    }

    public function test_iar_edit_action_loads_line_items_without_type_error(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session()->put('active_item_category_id', $category->id);

        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $item = \App\Models\Item::factory()->create(['item_category_id' => $category->id]);
        $pr = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $custodian->id,
            'purpose' => 'Office supplies',
            'pr_date' => now(),
            'pr_status' => AcquisitionPaperwork::STATUS_APPROVED,
            'pr_number' => 'PR-TEST-1',
        ]);
        $prLine = $pr->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'ream',
            'quantity' => 10,
        ]);

        $po = \App\Models\PurchaseOrder::query()->create([
            'acquisition_paperwork_id' => $pr->id,
            'status' => \App\Models\PurchaseOrder::STATUS_APPROVED,
            'number' => 'PO-TEST-1',
            'po_date' => now(),
            'supplier_name' => 'Acme',
        ]);
        $poLine = $po->lines()->create([
            'acquisition_paperwork_line_id' => $prLine->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'ream',
            'pr_quantity' => 10,
            'po_quantity' => 10,
            'is_ordered' => true,
            'unit_cost' => 12.5,
            'amount' => 125,
        ]);

        $iar = \App\Models\InspectionAcceptanceReport::query()->create([
            'purchase_order_id' => $po->id,
            'status' => \App\Models\InspectionAcceptanceReport::STATUS_DRAFT,
            'iar_date' => now(),
        ]);
        $iar->lines()->create([
            'purchase_order_line_id' => $poLine->id,
            'acquisition_paperwork_line_id' => $prLine->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'ream',
            'pr_quantity' => 10,
            'po_quantity' => 10,
            'iar_quantity' => 10,
            'unit_cost' => 12.5,
            'amount' => 125,
            'sort_order' => 1,
        ]);

        $line = $iar->lines()->first();
        $this->assertNotNull($line);

        $isEditable = new \ReflectionMethod(
            \App\Filament\Resources\Acquisitions\InspectionAcceptanceReports\Schemas\InspectionAcceptanceReportForm::class,
            'isEditable',
        );
        $isEditable->setAccessible(true);

        $this->assertTrue($isEditable->invoke(null, $iar));
        $this->assertTrue($isEditable->invoke(null, $line));
    }
}
