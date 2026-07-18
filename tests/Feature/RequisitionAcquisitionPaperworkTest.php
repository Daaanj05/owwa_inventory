<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Models\AcquisitionPaperwork;
use App\Models\AcquisitionPaperworkLine;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\User;
use App\Services\RequisitionPurchaseRequestService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RequisitionAcquisitionPaperworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_requisition_and_purchase_request_relationships_are_reciprocal(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $consolidator = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $requisition = Requisition::query()->create([
            'reference_code' => 'RIS-TEST-001',
            'office_id' => $office->id,
            'requested_by' => $consolidator->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $requisitionItem = $requisition->items()->create([
            'item_id' => $item->id,
            'quantity' => 5,
        ]);
        $paperwork = AcquisitionPaperwork::query()->create([
            'reference_code' => 'AP-TEST-001',
            'item_category_id' => $category->id,
            'office_id' => $office->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $custodian->id,
            'phase' => AcquisitionPaperwork::PHASE_PR,
        ]);
        $paperworkLine = $paperwork->lines()->create([
            'item_id' => $item->id,
            'description' => $item->name,
            'unit' => $item->unit,
            'quantity' => 5,
        ]);

        $paperwork->requisitions()->attach($requisition);
        $paperworkLine->requisitionItems()->attach($requisitionItem, ['quantity' => 5]);

        $this->assertTrue($paperwork->requisitions()->whereKey($requisition->id)->exists());
        $this->assertTrue($requisition->acquisitionPaperworks()->whereKey($paperwork->id)->exists());
        $this->assertSame(5, (int) $paperworkLine->requisitionItems()->firstOrFail()->pivot->quantity);
        $this->assertSame(5, (int) $requisitionItem->acquisitionPaperworkLines()->firstOrFail()->pivot->quantity);

        $service = app(RequisitionPurchaseRequestService::class);
        $this->assertSame(5, $service->alreadySourcedQuantity($requisitionItem));
        $this->assertSame(0, $service->remainingQuantityToSource($requisitionItem));
    }

    public function test_linked_requisitions_build_and_source_requested_quantities(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'unit' => 'box',
        ]);
        $consolidator = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        $requisitions = collect([4, 6])->map(function (int $quantity, int $index) use (
            $office,
            $consolidator,
            $item,
        ): Requisition {
            $requisition = Requisition::query()->create([
                'reference_code' => 'RIS-LINK-00'.($index + 1),
                'office_id' => $office->id,
                'requested_by' => $consolidator->id,
                'status' => Requisition::STATUS_PENDING,
            ]);
            $requisition->items()->create([
                'item_id' => $item->id,
                'quantity' => $quantity,
            ]);

            return $requisition;
        });

        $service = app(RequisitionPurchaseRequestService::class);
        $payload = $service->buildLinkedLinePayload(
            $requisitions->pluck('id')->all(),
            $category->id,
        );

        $this->assertCount(1, $payload);
        $this->assertSame($item->id, $payload[0]['item_id']);
        $this->assertSame(10, $payload[0]['quantity']);
        $this->assertNull($payload[0]['unit_cost']);

        $paperwork = AcquisitionPaperwork::query()->create([
            'reference_code' => 'AP-LINK-001',
            'item_category_id' => $category->id,
            'office_id' => $office->id,
            'requesting_office_id' => $office->id,
            'recorded_by' => $custodian->id,
            'phase' => AcquisitionPaperwork::PHASE_PR,
        ]);
        /** @var AcquisitionPaperworkLine $paperworkLine */
        $paperworkLine = $paperwork->lines()->create($payload[0]);

        $service->linkSelectedSources($paperwork, $requisitions->pluck('id')->all());

        $this->assertSame(2, $paperwork->requisitions()->count());
        $this->assertSame(
            10,
            (int) $paperworkLine->requisitionItems()->sum(
                'acquisition_paperwork_line_requisition_item.quantity',
            ),
        );
        $this->assertSame(
            10,
            $service->buildLinkedLinePayload(
                $requisitions->pluck('id')->all(),
                $category->id,
                $paperwork->id,
            )[0]['quantity'],
        );
    }

    public function test_selecting_linked_requisition_prefills_requested_quantity_in_pr_form(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'unit' => 'ream',
        ]);
        $consolidator = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);
        $requisition = Requisition::query()->create([
            'reference_code' => 'RIS-PREFILL-001',
            'office_id' => $office->id,
            'requested_by' => $consolidator->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $requisition->items()->create([
            'item_id' => $item->id,
            'quantity' => 12,
        ]);

        session()->put('active_item_category_id', $category->id);
        $this->actingAs($custodian);

        $component = Livewire::test(ListAcquisitions::class)
            ->mountAction(TestAction::make('create')->schemaComponent(true, 'content'))
            ->fillForm([
                'item_category_id' => $category->id,
                'requisitions' => [$requisition->id],
            ]);

        $lines = array_values($component->get('mountedActions')[0]['data']['lines'] ?? []);

        $this->assertCount(1, $lines);
        $this->assertSame($item->id, (int) $lines[0]['item_id']);
        $this->assertSame(12, (int) $lines[0]['quantity']);
        $this->assertNull($lines[0]['unit_cost'] ?? null);

        $component
            ->fillForm(['purpose' => 'Restock linked requisition'])
            ->callMountedAction()
            ->assertNotified();

        $paperwork = AcquisitionPaperwork::query()->latest('id')->firstOrFail();

        $this->assertSame(12, (int) $paperwork->lines()->firstOrFail()->quantity);
        $this->assertTrue($paperwork->requisitions()->whereKey($requisition->id)->exists());
        $this->assertSame(
            12,
            (int) $requisition->items()->firstOrFail()->acquisitionPaperworkLines()->sum(
                'acquisition_paperwork_line_requisition_item.quantity',
            ),
        );
    }
}
