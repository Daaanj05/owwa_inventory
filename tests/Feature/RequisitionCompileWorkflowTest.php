<?php

namespace Tests\Feature;

use App\Filament\Pages\RegionalSupplyCatalog;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Requisitions\RequisitionResource;
use App\Models\Item;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\RequisitionCompileService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RequisitionCompileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_employee_requisitions_are_not_eligible_for_compile(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 3,
        ]);

        $eligible = app(RequisitionCompileService::class)->filterEligible(collect([$requisition]));

        $this->assertCount(0, $eligible);
    }

    public function test_approved_uncompiled_employee_requisitions_are_eligible(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $requisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 3,
        ]);

        $eligible = app(RequisitionCompileService::class)->filterEligible(collect([$requisition]));

        $this->assertCount(1, $eligible);
    }

    public function test_compile_service_merges_quantities_with_source_summary(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create(['name' => 'Bond Paper']);

        $first = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'reference_code' => 'REQ-A',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $first->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $second = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'reference_code' => 'REQ-B',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $second->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        $merged = app(RequisitionCompileService::class)->mergedLineItems(collect([$first, $second]));

        $this->assertCount(1, $merged);
        $this->assertSame(7, $merged[0]['quantity']);
        $this->assertStringContainsString('REQ-A: 2', $merged[0]['line_source_summary']);
        $this->assertStringContainsString('REQ-B: 5', $merged[0]['line_source_summary']);
    }

    public function test_eligible_employee_requisition_options_lists_approved_uncompiled_requests(): void
    {
        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $eligible = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'reference_code' => 'REQ-ELIGIBLE',
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $eligible->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->actingAs($uc);

        $options = app(RequisitionCompileService::class)->eligibleEmployeeRequisitionOptions($uc);

        $this->assertArrayHasKey($eligible->id, $options);
        $this->assertCount(1, $options);
    }

    public function test_unit_consolidator_can_send_adjusted_quantities_to_supply_custodian(): void
    {
        $office = Office::factory()->create();
        $item = Item::factory()->create();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 10,
        ]);

        $consolidated = app(RequisitionCompileService::class)->createConsolidatedRequisition(
            $uc,
            collect([$employeeRequisition]),
            [
                ['item_id' => $item->id, 'quantity' => 8, 'remarks' => 'Consolidated line note'],
            ],
            'Office supplies for Q2',
        );

        $this->assertSame(Requisition::STATUS_PENDING, $consolidated->status);
        $this->assertSame('Office supplies for Q2', $consolidated->purpose);
        $this->assertSame(8, $consolidated->items()->first()?->quantity);
        $this->assertSame('Consolidated line note', $consolidated->items()->first()?->remarks);
        $this->assertSame($consolidated->id, $employeeRequisition->fresh()->compiled_into_requisition_id);

        $this->actingAs($custodian);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $visibleToCustodian = RequisitionResource::getEloquentQuery()
            ->whereKey($consolidated->id)
            ->exists();

        $this->assertTrue($visibleToCustodian);
    }

    public function test_unit_consolidator_can_compile_via_new_requisition_modal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $item = Item::factory()->create();

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $employeeRequisition = Requisition::query()->create([
            'office_id' => $office->id,
            'requested_by' => $employee->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);
        RequisitionItem::query()->create([
            'requisition_id' => $employeeRequisition->id,
            'item_id' => $item->id,
            'quantity' => 10,
        ]);

        $this->actingAs($uc);

        Livewire::test(ListRequisitions::class)
            ->callAction(
                TestAction::make('create')->schemaComponent(true, 'content'),
                data: [
                    'purpose' => 'Compiled office supplies',
                    'source_requisition_ids' => [$employeeRequisition->id],
                    'items' => [
                        [
                            'item_category_id' => $item->item_category_id,
                            'item_id' => $item->id,
                            'quantity' => 6,
                            'remarks' => 'Per employee request',
                        ],
                    ],
                ])
            ->assertNotified();

        $consolidated = Requisition::query()
            ->where('requested_by', $uc->id)
            ->where('status', Requisition::STATUS_PENDING)
            ->latest('id')
            ->first();

        $this->assertNotNull($consolidated);
        $this->assertSame('Compiled office supplies', $consolidated->purpose);
        $this->assertSame(6, $consolidated->items()->first()?->quantity);
        $this->assertSame('Per employee request', $consolidated->items()->first()?->remarks);
        $this->assertSame($consolidated->id, $employeeRequisition->fresh()->compiled_into_requisition_id);
    }

    public function test_unit_consolidator_create_form_includes_compile_picker_and_purpose_without_header_remarks(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        $this->actingAs($uc);

        Livewire::test(ListRequisitions::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->assertFormFieldExists('source_requisition_ids')
            ->assertFormFieldExists('purpose')
            ->assertFormFieldDoesNotExist('remarks');
    }

    public function test_employee_create_form_does_not_include_compile_picker(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);

        $this->actingAs($employee);

        Livewire::test(ListRequisitions::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->assertFormFieldDoesNotExist('source_requisition_ids')
            ->assertFormFieldDoesNotExist('purpose')
            ->assertFormFieldDoesNotExist('remarks')
            ->assertFormFieldExists('items');
    }

    public function test_catalog_request_url_opens_list_with_create_params(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $this->actingAs($employee);

        $url = Livewire::test(RegionalSupplyCatalog::class)
            ->instance()
            ->requestItemUrl($item->id);

        $this->assertStringContainsString('create=1', $url);
        $this->assertStringContainsString('item_id='.$item->id, $url);
    }

    public function test_catalog_deep_link_prefills_create_modal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'office_id' => $office->id,
        ]);
        $item = Item::factory()->create();

        $this->actingAs($employee);

        $component = Livewire::test(ListRequisitions::class, [
            'create' => 1,
            'item_id' => $item->id,
        ])
            ->assertActionMounted(TestAction::make('create')->schemaComponent(true, 'content'));

        $items = array_values($component->get('mountedActions')[0]['data']['items'] ?? []);

        $this->assertNotEmpty($items);
        $this->assertSame((string) $item->id, (string) ($items[0]['item_id'] ?? ''));
        $this->assertSame((string) $item->item_category_id, (string) ($items[0]['item_category_id'] ?? ''));
        $this->assertSame(1.0, (float) ($items[0]['quantity'] ?? 0));
    }
}
