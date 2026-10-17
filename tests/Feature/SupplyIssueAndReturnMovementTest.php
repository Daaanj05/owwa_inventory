<?php

namespace Tests\Feature;

use App\Filament\Resources\PropertyActionRequests\Actions\PropertyActionRequestTableActions;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\Department;
use App\Models\InventoryUnit;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\PropertyActionRequest;
use App\Models\PropertyActionRequestLine;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Transfer;
use App\Models\User;
use App\Services\InventoryQrLabelService;
use App\Services\InventoryStockService;
use App\Services\OwwaItemReportService;
use App\Services\PropertyActionRequestWorkflowService;
use App\Services\RequisitionFulfillmentService;
use App\Services\StockOpeningBalanceBatchService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplyIssueAndReturnMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_from_opening_balance_deducts_regional_supply_and_records_the_property_card(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $supply = Office::factory()->create(['is_regional_supply' => true, 'name' => 'Regional Supply']);
        $office = Office::factory()->create(['name' => 'Field Office']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Bond Paper',
            'unit' => 'ream',
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $supply->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $this->actingAs($custodian);

        $batch = app(StockOpeningBalanceBatchService::class)->saveDraft(
            lines: [[
                'item_id' => $item->id,
                'quantity' => 10,
                'unit_cost' => 15,
            ]],
            memo: 'Opening',
            itemCategoryId: $category->id,
            recordedBy: $custodian,
        );
        app(StockOpeningBalanceBatchService::class)->confirm($batch);

        $stock = app(InventoryStockService::class);
        $this->assertSame(10, $stock->getStock($item->id, $supply->id));

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0901',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 4,
        ]);

        app(RequisitionFulfillmentService::class)->issueLines($requisition, $custodian, [[
            'requisition_item_id' => $line->id,
            'quantity_to_issue' => 3,
        ]], now()->toDateString(), [
            'ris_issued_by_name' => 'Supply Custodian',
            'ris_received_by_name' => 'Unit Custodian',
            'custodian_printed_name' => 'Supply Custodian',
            'received_by_printed_name' => 'Unit Custodian',
        ]);

        $stock->forgetMovementTotalsCache();
        $this->assertSame(7, $stock->getStock($item->id, $supply->id));
        $this->assertSame(0, $stock->getStock($item->id, $office->id));

        $issuance = Issuance::query()->where('requisition_id', $requisition->id)->first();
        $this->assertNotNull($issuance);
        $this->assertSame($office->id, $issuance->office_id);
        $this->assertSame($supply->id, $issuance->issued_from_office_id);
        $this->assertSame('15.00', number_format((float) $issuance->unit_cost, 2, '.', ''));

        $history = app(OwwaItemReportService::class)->buildTransactionHistoriesForItems([$item->id], $supply->id);
        $issuedQty = collect($history[$item->id] ?? [])->where('type', 'issue')->sum('issue_qty');
        $this->assertSame(3, (int) $issuedQty);
    }

    public function test_opening_balance_qr_labels_match_the_confirmed_quantity(): void
    {
        $office = Office::factory()->create(['is_regional_supply' => true]);
        $category = ItemCategory::query()->firstOrCreate(
            ['name' => 'Property, Plant and Equipment'],
            ['description' => 'Property, plant and equipment'],
        );
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Office Chair',
            'ppe_property_number' => 'PPE-2026-CHAIR',
            'ppe_type' => \App\Support\PpePropertyType::TechnicalScientificEquipment,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $this->actingAs($user);

        $batch = app(StockOpeningBalanceBatchService::class)->saveDraft(
            lines: [[
                'item_id' => $item->id,
                'quantity' => 5,
                'unit_cost' => 75000,
            ]],
            memo: null,
            itemCategoryId: $category->id,
            recordedBy: $user,
        );
        $confirmed = app(StockOpeningBalanceBatchService::class)->confirm($batch);

        $this->assertCount(5, app(InventoryQrLabelService::class)->labelsForOpeningBalanceBatch($confirmed));
        $this->assertSame(5, InventoryUnit::query()->where('item_id', $item->id)->whereNotNull('stock_opening_balance_id')->count());
    }

    public function test_return_to_stock_increases_regional_supply(): void
    {
        $supply = Office::factory()->create(['is_regional_supply' => true, 'name' => 'Regional Supply']);
        $office = Office::factory()->create(['name' => 'Field Office']);
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id, 'name' => 'Stapler']);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN, 'office_id' => $supply->id]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);

        $this->actingAs($custodian);

        $batch = app(StockOpeningBalanceBatchService::class)->saveDraft(
            lines: [[
                'item_id' => $item->id,
                'quantity' => 2,
                'unit_cost' => 500,
            ]],
            memo: null,
            itemCategoryId: $category->id,
            recordedBy: $custodian,
        );
        app(StockOpeningBalanceBatchService::class)->confirm($batch);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0902',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        app(RequisitionFulfillmentService::class)->issueLines($requisition, $custodian, [[
            'requisition_item_id' => $line->id,
            'quantity_to_issue' => 1,
        ]], now()->toDateString());

        $stock = app(InventoryStockService::class);
        $stock->forgetMovementTotalsCache();
        $this->assertSame(1, $stock->getStock($item->id, $supply->id));

        $issuance = Issuance::query()->where('item_id', $item->id)->firstOrFail();
        $request = PropertyActionRequest::query()->create([
            'reference_code' => 'PAR-RET-1',
            'action_type' => PropertyActionRequest::ACTION_RETURN,
            'requested_by' => $employee->id,
            'accountable_user_id' => $employee->id,
            'office_id' => $office->id,
            'department_id' => $department->id,
            'reason_code' => 'serviceable_not_needed',
            'status' => PropertyActionRequest::STATUS_APPROVED,
        ]);
        PropertyActionRequestLine::query()->create([
            'property_action_request_id' => $request->id,
            'issuance_id' => $issuance->id,
            'quantity' => 1,
            'sort_order' => 1,
        ]);

        app(PropertyActionRequestWorkflowService::class)->execute(
            $request,
            $custodian,
            PropertyActionRequest::OUTCOME_RETURN_TO_STOCK,
        );

        $transfer = Transfer::query()->where('transfer_type', 'return')->first();
        $this->assertNotNull($transfer);
        $this->assertSame($supply->id, $transfer->to_office_id);

        $stock->forgetMovementTotalsCache();
        $this->assertSame(2, $stock->getStock($item->id, $supply->id));
        $this->assertSame(
            InventoryUnit::STATUS_IN_STOCK,
            InventoryUnit::query()->where('item_id', $item->id)->value('status'),
        );
    }

    public function test_open_transfer_url_includes_the_category_and_avoids_the_bare_view_route(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $from = Office::factory()->create();
        $to = Office::factory()->create();
        $transfer = Transfer::query()->create([
            'reference_code' => 'PTR-1',
            'transfer_type' => Transfer::TYPE_RELOCATE,
            'from_office_id' => $from->id,
            'to_office_id' => $to->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'transfer_date' => now()->toDateString(),
        ]);

        $url = TransferResource::viewModalUrl($transfer->id, ['category' => $category->id]);

        $this->assertStringContainsString('tableAction=view', $url);
        $this->assertStringContainsString('tableActionRecord='.$transfer->id, $url);
        $this->assertStringContainsString('category='.$category->id, $url);
        $this->assertStringNotContainsString('/transfers/'.$transfer->id, $url);
        $this->assertTrue(method_exists(PropertyActionRequestTableActions::class, 'openTransferAction'));
    }

    public function test_uc_cannot_create_a_requisition_without_an_employee_source(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $department = Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Admin',
            'code' => 'ADM',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
            'department_id' => $department->id,
        ]);
        $uc->syncOfficeAssignments([
            ['office_id' => $office->id, 'department_id' => $department->id],
        ]);

        $this->actingAs($uc);

        Livewire::test(ListRequisitions::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->fillForm([
                'office_id' => $office->id,
                'department_id' => $department->id,
                'purpose' => 'Office use',
                'source_requisition_ids' => [],
            ])
            ->callMountedAction()
            ->assertHasFormErrors(['source_requisition_ids']);

        $this->assertDatabaseCount('requisitions', 0);
    }
}
