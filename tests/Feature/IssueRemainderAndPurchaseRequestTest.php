<?php

namespace Tests\Feature;

use App\Filament\Resources\Acquisitions\Pages\ListAcquisitions;
use App\Filament\Resources\Requisitions\Actions\CustodianRequisitionActions;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Requisitions\Schemas\RequisitionInfolistSchema;
use App\Filament\Resources\Requisitions\Schemas\RequisitionIssuanceFormSchema;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Support\OwwaReferenceLabels;
use App\Support\RequisitionLineFulfillmentState;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class IssueRemainderAndPurchaseRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_remainder_stays_hidden_and_does_not_issue_when_regional_stock_is_zero(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $regional = Office::factory()->create([
            'name' => 'Regional Supply',
            'is_regional_supply' => true,
        ]);
        $unit = Office::factory()->create(['name' => 'Unit Office']);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Backordered Air Freshener',
        ]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-UNIT-ONLY',
            'item_id' => $item->id,
            'office_id' => $unit->id,
            'quantity' => 40,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $unit->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $regional->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-10-0001',
            'office_id' => $unit->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'purpose' => 'office supplies',
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 25,
            'quantity_issued' => 0,
            'stock_at_request' => 0,
            'issue_remarks' => 'Bat andami',
        ]);

        $this->actingAs($custodian);

        Livewire::test(ListRequisitions::class)
            ->mountTableAction('view', $requisition)
            ->assertActionHidden(TestAction::make('issueRemainder'));

        $submit = new ReflectionMethod(CustodianRequisitionActions::class, 'runIssueAction');
        $submit->invoke(
            null,
            $requisition,
            [
                'issuance_date' => now()->toDateString(),
                'lines' => [[
                    'requisition_item_id' => $line->id,
                    'quantity_to_issue' => 5,
                    'issue_remarks' => 'Bat andami',
                ]],
            ],
            'Stock issued',
            false,
        );

        $line->refresh();

        $this->assertSame(0, (int) $line->quantity_issued);
        $this->assertSame(RequisitionLineFulfillmentState::BACKORDERED, $line->fulfillmentState());
        $this->assertSame(0, DB::table('issuances')->where('requisition_id', $requisition->id)->count());
    }

    public function test_issue_remainder_lines_omit_fully_issued_and_zero_stock_rows(): void
    {
        $regional = Office::factory()->create([
            'name' => 'Regional Supply',
            'is_regional_supply' => true,
        ]);
        $unit = Office::factory()->create(['name' => 'Unit Office']);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $issuedItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Battery AAA',
        ]);
        $waitingItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Wash Mitt',
        ]);
        $readyItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Air Freshener',
        ]);

        DB::table('acquisitions')->insert([
            [
                'reference_code' => 'ACQ-READY',
                'item_id' => $readyItem->id,
                'office_id' => $regional->id,
                'quantity' => 8,
                'acquisition_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'reference_code' => 'ACQ-ISSUED',
                'item_id' => $issuedItem->id,
                'office_id' => $regional->id,
                'quantity' => 8,
                'acquisition_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $unit->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-10-0002',
            'office_id' => $unit->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        $fullyIssued = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $issuedItem->id,
            'quantity' => 2,
            'quantity_issued' => 2,
            'stock_at_request' => 2,
        ]);
        $stillZero = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $waitingItem->id,
            'quantity' => 50,
            'quantity_issued' => 0,
            'stock_at_request' => 0,
        ]);
        $ready = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $readyItem->id,
            'quantity' => 25,
            'quantity_issued' => 20,
            'stock_at_request' => 0,
        ]);

        $lines = RequisitionIssuanceFormSchema::defaultLines($requisition->fresh(), true);

        $this->assertCount(1, $lines);
        $this->assertSame($ready->id, $lines[0]['requisition_item_id']);
        $this->assertSame(8, $lines[0]['stock_available']);
        $this->assertNotContains($fullyIssued->id, array_column($lines, 'requisition_item_id'));
        $this->assertNotContains($stillZero->id, array_column($lines, 'requisition_item_id'));
    }

    public function test_create_pr_from_requisition_opens_prefilled_modal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $regional = Office::factory()->create([
            'name' => 'Regional Supply',
            'is_regional_supply' => true,
        ]);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Zero Stock Bond Paper',
        ]);
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $regional->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $regional->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-10-0003',
            'office_id' => $regional->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
            'purpose' => 'office supplies',
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 12,
            'quantity_issued' => 0,
            'stock_at_request' => 0,
        ]);

        $this->actingAs($custodian);

        $livewire = Livewire::withQueryParams([
            'category' => (string) $category->id,
            'create_from_requisition' => $requisition->id,
        ])->test(ListAcquisitions::class);

        $livewire->assertActionMounted('createPr');

        $lines = array_values($livewire->get('mountedActions')[0]['data']['lines'] ?? []);

        $this->assertNotEmpty($lines);
        $this->assertSame($item->id, (int) $lines[0]['item_id']);
        $this->assertSame(12, (int) $lines[0]['quantity']);
    }

    public function test_custodian_requisition_view_shows_regional_available_stock(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $regional = Office::factory()->create([
            'name' => 'Regional Supply',
            'is_regional_supply' => true,
        ]);
        $unit = Office::factory()->create(['name' => 'Unit Office']);
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Counted Marker',
        ]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-VIEW-STOCK',
            'item_id' => $item->id,
            'office_id' => $regional->id,
            'quantity' => 17,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $unit->id,
        ]);
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
            'office_id' => $regional->id,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-10-0004',
            'office_id' => $unit->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_ACCEPTED,
        ]);

        RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 4,
            'quantity_issued' => 0,
            'stock_at_request' => 0,
        ]);

        $this->actingAs($custodian);

        $livewire = Livewire::test(ListRequisitions::class)->instance();
        $html = Schema::make($livewire)
            ->record($requisition)
            ->components([
                RequisitionInfolistSchema::requestedItemsSection(),
            ])
            ->toHtml();

        $this->assertStringContainsString('Available stock', $html);
        $this->assertStringContainsString(OwwaReferenceLabels::assetIdentifierTableHeader(), $html);
        $this->assertStringContainsString('Counted Marker', $html);
        $this->assertStringContainsString('17', $html);
        $this->assertMatchesRegularExpression(
            '/Available stock.*Requested.*Issued.*Remaining.*Status.*Restock.*Remarks/s',
            $html,
        );
    }
}
