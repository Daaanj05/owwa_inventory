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
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
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

        $this->actingAs($custodian);

        $component = Livewire::withQueryParams([
            'category' => (string) $category->id,
            'create_from_requisition' => $requisition->id,
        ])->test(ListAcquisitions::class);

        $component->assertActionMounted('createPr');

        $lines = array_values($component->get('mountedActions')[0]['data']['lines'] ?? []);

        $this->assertCount(1, $lines);
        $this->assertSame($item->id, (int) $lines[0]['item_id']);
        $this->assertSame(12, (int) $lines[0]['quantity']);
        $this->assertNull($lines[0]['unit_cost'] ?? null);

        $component
            ->fillForm([
                'office_id' => $office->id,
                'requesting_office_id' => $office->id,
                'purpose' => 'Restock linked requisition',
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
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

    public function test_pr_lines_cannot_be_added_when_requisitions_are_linked(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

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
            'reference_code' => 'RIS-LOCKED-001',
            'office_id' => $office->id,
            'requested_by' => $consolidator->id,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $requisition->items()->create([
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        $this->actingAs($custodian);

        $component = Livewire::withQueryParams([
            'category' => (string) $category->id,
            'create_from_requisition' => $requisition->id,
        ])->test(ListAcquisitions::class)
            ->assertActionMounted('createPr')
            ->assertDontSee('Add line')
            ->assertSee('Items and requested quantities come from the linked requisitions.');

        $this->assertFalse($this->mountedPrLinesRepeater($component)->isAddable());
        $this->assertFalse($this->mountedPrLinesRepeater($component)->isDeletable());

        $component->fillForm(['requisitions' => []]);

        $this->assertTrue($this->mountedPrLinesRepeater($component)->isAddable());
        $this->assertTrue($this->mountedPrLinesRepeater($component)->isDeletable());
    }

    public function test_pr_requested_by_prefills_from_single_linked_requisition(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, $category, $item, $custodian] = $this->requesterFixtures();
        $requisition = $this->linkableRequisition($office, $item, 'RIS-REQBY-001', 'Ana Reyes');

        $this->actingAs($custodian);

        $component = Livewire::withQueryParams([
            'category' => (string) $category->id,
            'create_from_requisition' => $requisition->id,
        ])->test(ListAcquisitions::class)
            ->assertActionMounted('createPr');

        $this->assertSame('Ana Reyes', $component->get('mountedActions')[0]['data']['requested_by_name']);

        $fallbackRequisition = $this->linkableRequisition($office, $item, 'RIS-REQBY-002', null, 'Ben Cruz');

        $component->fillForm(['requisitions' => [$fallbackRequisition->id]]);

        $this->assertSame('Ben Cruz', $component->get('mountedActions')[0]['data']['requested_by_name']);
    }

    public function test_pr_requested_by_stays_blank_when_linked_requisitions_have_different_requesters(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, $category, $item, $custodian] = $this->requesterFixtures();
        $first = $this->linkableRequisition($office, $item, 'RIS-REQBY-101', 'Ana Reyes');
        $second = $this->linkableRequisition($office, $item, 'RIS-REQBY-102', 'Ben Cruz');

        $this->actingAs($custodian);

        $component = Livewire::withQueryParams([
            'category' => (string) $category->id,
            'create_from_requisition' => $first->id,
        ])->test(ListAcquisitions::class)
            ->assertActionMounted('createPr')
            ->fillForm(['requisitions' => [$first->id, $second->id]]);

        $this->assertNull($component->get('mountedActions')[0]['data']['requested_by_name']);
        $this->assertStringContainsString(
            'Linked requisitions have different requesters (Ana Reyes, Ben Cruz). Enter the official requesting this consolidated PR.',
            $this->mountedPrField($component, 'requested_by_name')->getChildSchema(TextInput::BELOW_CONTENT_SCHEMA_KEY)->toHtml(),
        );
    }

    public function test_pr_requested_by_typed_value_is_not_overwritten_by_linked_requisition(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        [$office, $category, $item, $custodian] = $this->requesterFixtures();
        $first = $this->linkableRequisition($office, $item, 'RIS-REQBY-201', 'Ana Reyes');
        $second = $this->linkableRequisition($office, $item, 'RIS-REQBY-202', 'Ben Cruz');

        $this->actingAs($custodian);

        $component = Livewire::withQueryParams([
            'category' => (string) $category->id,
            'create_from_requisition' => $first->id,
        ])->test(ListAcquisitions::class)
            ->assertActionMounted('createPr')
            ->fillForm(['requested_by_name' => 'Regional Director'])
            ->fillForm(['requisitions' => [$second->id]]);

        $this->assertSame('Regional Director', $component->get('mountedActions')[0]['data']['requested_by_name']);

        $component->fillForm(['requisitions' => []]);

        $this->assertSame('Regional Director', $component->get('mountedActions')[0]['data']['requested_by_name']);
    }

    /**
     * @return array{0: Office, 1: ItemCategory, 2: Item, 3: User}
     */
    protected function requesterFixtures(): array
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $office->id,
        ]);

        return [$office, $category, $item, $custodian];
    }

    protected function linkableRequisition(Office $office, Item $item, string $referenceCode, ?string $risRequestedByName, string $consolidatorName = 'Unit Consolidator'): Requisition
    {
        $consolidator = User::factory()->create([
            'name' => $consolidatorName,
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        $requisition = Requisition::query()->create([
            'reference_code' => $referenceCode,
            'office_id' => $office->id,
            'requested_by' => $consolidator->id,
            'ris_requested_by_name' => $risRequestedByName,
            'status' => Requisition::STATUS_PENDING,
        ]);
        $requisition->items()->create([
            'item_id' => $item->id,
            'quantity' => 3,
        ]);

        return $requisition;
    }

    protected function mountedPrField(Testable $component, string $name): TextInput
    {
        $field = $component->instance()
            ->getSchema('mountedActionSchema0')
            ?->getComponent(
                fn (mixed $schemaComponent): bool => $schemaComponent instanceof TextInput && $schemaComponent->getName() === $name,
                withHidden: true,
            );

        $this->assertInstanceOf(TextInput::class, $field);

        return $field;
    }

    protected function mountedPrLinesRepeater(Testable $component): Repeater
    {
        $repeater = $component->instance()
            ->getSchema('mountedActionSchema0')
            ?->getComponent(
                fn (mixed $schemaComponent): bool => $schemaComponent instanceof Repeater && $schemaComponent->getName() === 'lines',
                withHidden: true,
            );

        $this->assertInstanceOf(Repeater::class, $repeater);

        return $repeater;
    }
}
