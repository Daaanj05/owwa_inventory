<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\DeliveryTerms\Pages\ManageDeliveryTerms;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\AcquisitionPaperwork;
use App\Models\DeliveryTerm;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Models\User;
use App\Services\AcquisitionPaperworkCompletionService;
use App\Services\PurchaseOrderWorkflowService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseOrderSupplierDeliveryTermTest extends TestCase
{
    use RefreshDatabase;

    public function test_custodian_can_manage_suppliers_and_delivery_terms(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);
        $this->actingAs($custodian);

        $supplierCreate = Livewire::test(ManageSuppliers::class)
            ->mountAction('create');

        $addressKey = array_key_first($supplierCreate->get('mountedActions.0.data.addresses') ?? []);

        $supplierCreate
            ->fillForm([
                'name' => 'Acme Trading',
                'tin' => '123456789',
                'addresses' => filled($addressKey)
                    ? [
                        $addressKey => [
                            'address' => '123 Main St',
                            'is_default' => true,
                        ],
                    ]
                    : [
                        ['address' => '123 Main St', 'is_default' => true],
                    ],
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Supplier::class, [
            'name' => 'Acme Trading',
            'tin' => '123456789',
        ]);
        $this->assertDatabaseHas(SupplierAddress::class, [
            'address' => '123 Main St',
            'is_default' => true,
        ]);

        Livewire::test(ManageDeliveryTerms::class)
            ->callAction('create', [
                'label' => 'FOB Destination',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(DeliveryTerm::class, [
            'label' => 'FOB Destination',
            'archived_at' => null,
        ]);

        $this->assertFalse(Schema::hasColumn('suppliers', 'delivery_term'));
    }

    public function test_po_edit_modal_uses_supplier_and_delivery_term_selects(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session(['active_item_category_id' => $category->id]);

        $supplier = Supplier::remember('Acme Supplies', '999888777', 'Warehouse Road');
        DeliveryTerm::remember('FOB Destination');

        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $custodian->id,
            'purpose' => 'Office supplies for regional use',
            'pr_date' => now()->toDateString(),
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);
        $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'ream',
            'quantity' => 5,
        ]);

        $completion = app(AcquisitionPaperworkCompletionService::class);
        $completion->submitPr($paperwork->fresh());
        $completion->approvePr($paperwork->fresh());

        $po = app(PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork->fresh());
        $this->assertNull($po->number);
        $this->assertNull($paperwork->fresh()->po_number);
        $this->assertTrue($po->isUnsavedPoDraft());
        $this->assertSame('PO draft', $po->statusLabel());
        $po->lines()->update([
            'is_ordered' => true,
            'po_quantity' => 5,
            'unit_cost' => 10,
            'amount' => 50,
        ]);

        $this->actingAs($custodian);

        $editUrl = \App\Filament\Resources\Acquisitions\PurchaseOrders\PurchaseOrderResource::viewModalUrl($po);
        $this->assertStringContainsString('tableAction=edit', $editUrl);

        $test = Livewire::test(ListPurchaseOrders::class)
            ->callAction(TestAction::make('edit')->table($po), [
                'supplier_id' => $supplier->id,
                'supplier_name' => 'Acme Supplies',
                'supplier_address' => 'Warehouse Road',
                'supplier_tin' => '000000000',
                'delivery_term' => 'FOB Destination',
                'mode_of_procurement' => 'Shopping',
                'place_of_delivery' => $office->name,
                'date_of_delivery' => now()->addDays(7)->toDateString(),
                'payment_term' => 'COD',
                'technical_specifications' => 'N/A',
            ])
            ->assertHasNoFormErrors();

        $po->refresh();

        $this->assertSame($supplier->id, $po->supplier_id);
        $this->assertSame('Acme Supplies', $po->supplier_name);
        $this->assertSame('999888777', $po->supplier_tin);
        $this->assertSame('Warehouse Road', $po->supplier_address);
        $this->assertSame('FOB Destination', $po->delivery_term);
        $this->assertNotNull($po->number);
        $this->assertNotNull($po->submitted_at);
        $this->assertTrue($po->isPendingApproval());
        $this->assertSame('PO pending approval', $po->statusLabel());
        $this->assertFalse($po->isUnsavedPoDraft());
        $this->assertTrue($po->isEditable());

        $viewUrl = \App\Filament\Resources\Acquisitions\PurchaseOrders\PurchaseOrderResource::viewModalUrl($po);
        $this->assertStringContainsString('tableAction=view', $viewUrl);
        $test->assertRedirect($viewUrl);
    }

    public function test_po_unit_cost_zero_is_treated_as_missing(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $custodian->id,
            'purpose' => 'Office supplies for regional use',
            'pr_date' => now()->toDateString(),
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);
        $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'ream',
            'quantity' => 5,
        ]);

        $completion = app(AcquisitionPaperworkCompletionService::class);
        $completion->submitPr($paperwork->fresh());
        $completion->approvePr($paperwork->fresh());

        $po = app(PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork->fresh());
        $po->update([
            'supplier_name' => 'Acme Supplies',
            'supplier_address' => 'Warehouse Road',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => $office->name,
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => 'COD',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update([
            'is_ordered' => true,
            'po_quantity' => 5,
            'unit_cost' => 0,
            'amount' => 0,
        ]);

        $missing = $po->fresh(['lines'])->missingFields();
        $this->assertNotEmpty($missing);
        $this->assertTrue(
            collect($missing)->contains(fn (string $field): bool => str_contains($field, 'unit cost')),
        );
    }

    public function test_po_unsaved_draft_opens_edit_and_submitted_po_opens_view(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session(['active_item_category_id' => $category->id]);

        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $custodian->id,
            'purpose' => 'Office supplies for regional use',
            'pr_date' => now()->toDateString(),
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);
        $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'ream',
            'quantity' => 5,
        ]);

        $completion = app(AcquisitionPaperworkCompletionService::class);
        $completion->submitPr($paperwork->fresh());
        $completion->approvePr($paperwork->fresh());

        $po = app(PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork->fresh());
        $po->update([
            'supplier_name' => 'Acme Supplies',
            'supplier_address' => 'Warehouse Road',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => $office->name,
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => 'COD',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update([
            'is_ordered' => true,
            'po_quantity' => 5,
            'unit_cost' => 10,
            'amount' => 50,
        ]);

        $this->actingAs($custodian);

        Livewire::withQueryParams([
            'tableAction' => 'edit',
            'tableActionRecord' => (string) $po->getKey(),
            'category' => (string) $category->id,
        ])
            ->test(ListPurchaseOrders::class)
            ->assertSet('defaultTableAction', 'edit');

        $this->assertStringContainsString(
            'tableAction=edit',
            \App\Filament\Resources\Acquisitions\PurchaseOrders\PurchaseOrderResource::viewModalUrl($po),
        );

        app(PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));
        $po->refresh();

        $this->assertTrue($po->isPendingApproval());
        $this->assertSame('PO pending approval', $po->statusLabel());

        Livewire::withQueryParams([
            'tableAction' => 'edit',
            'tableActionRecord' => (string) $po->getKey(),
            'category' => (string) $category->id,
        ])
            ->test(ListPurchaseOrders::class)
            ->assertSet('defaultTableAction', 'view');

        Livewire::test(ListPurchaseOrders::class)
            ->set('defaultTableAction', 'edit')
            ->set('defaultTableActionRecord', (string) $po->getKey())
            ->assertSet('defaultTableAction', 'edit')
            ->mountTableAction('edit', $po)
            ->assertActionMounted(TestAction::make('edit')->table($po));

        Livewire::test(ListPurchaseOrders::class)
            ->mountTableAction('view', $po)
            ->callAction(TestAction::make('editPo')->table($po))
            ->assertActionMounted(TestAction::make('edit')->table($po));
    }

    public function test_approve_po_is_visible_on_view_after_submit(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create(['is_regional_supply' => true]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        session(['active_item_category_id' => $category->id]);

        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $paperwork = AcquisitionPaperwork::query()->create([
            'office_id' => $office->id,
            'item_category_id' => $category->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $custodian->id,
            'purpose' => 'Office supplies for regional use',
            'pr_date' => now()->toDateString(),
            'pr_status' => AcquisitionPaperwork::STATUS_DRAFT,
        ]);
        $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => 'ream',
            'quantity' => 5,
        ]);

        $completion = app(AcquisitionPaperworkCompletionService::class);
        $completion->submitPr($paperwork->fresh());
        $completion->approvePr($paperwork->fresh());

        $po = app(PurchaseOrderWorkflowService::class)->createFromApprovedPr($paperwork->fresh());
        $po->update([
            'supplier_name' => 'Acme Supplies',
            'supplier_address' => 'Warehouse Road',
            'mode_of_procurement' => 'Shopping',
            'place_of_delivery' => $office->name,
            'date_of_delivery' => now()->addDays(7)->toDateString(),
            'payment_term' => 'COD',
            'technical_specifications' => 'N/A',
            'po_date' => now()->toDateString(),
        ]);
        $po->lines()->update([
            'is_ordered' => true,
            'po_quantity' => 5,
            'unit_cost' => 10,
            'amount' => 50,
        ]);

        app(PurchaseOrderWorkflowService::class)->submit($po->fresh(['lines']));
        $po->refresh();

        $this->assertNotNull($po->submitted_at);
        $this->assertNotNull($po->number);
        $this->assertTrue($po->isPendingApproval());
        $this->assertSame(AcquisitionPaperwork::STATUS_PENDING_APPROVAL, $paperwork->fresh()->po_status);

        $this->actingAs($custodian);

        Livewire::test(ListPurchaseOrders::class)
            ->mountTableAction('view', $po)
            ->assertActionVisible(TestAction::make('approvePo'));
    }
}
