<?php

namespace Tests\Feature;

use App\Filament\Resources\Issuances\IssuanceResource;
use App\Filament\Resources\Requisitions\Actions\CustodianRequisitionActions;
use App\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Filament\Resources\Requisitions\Schemas\RequisitionIssuanceFormSchema;
use App\Models\Issuance;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Office;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Services\OwwaTemplateExportService;
use App\Services\RequisitionFulfillmentService;
use App\Support\RequisitionLineDisplay;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class RecordIssuanceFromRequisitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_resource_cannot_create_ad_hoc(): void
    {
        $this->assertFalse(IssuanceResource::canCreate());
    }

    public function test_custodian_can_accept_and_issue_from_pending_requisition(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-FEAT-1',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 50,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0005',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 5,
        ]);

        $this->actingAs($custodian);

        Livewire::test(ListRequisitions::class)
            ->assertCanSeeTableRecords([$requisition])
            ->assertActionVisible(TestAction::make('acceptAndIssue')->table($requisition));

        $result = app(RequisitionFulfillmentService::class)->issueLines(
            $requisition,
            $custodian,
            [
                [
                    'requisition_item_id' => $line->id,
                    'quantity_to_issue' => 5,
                ],
            ],
            '2026-04-01',
        );

        $this->assertSame(1, $result['created']);

        $issuance = Issuance::query()->with('requisition')->first();

        $this->assertNotNull($issuance);
        $this->assertSame($requisition->id, $issuance->requisition_id);
        $this->assertNotSame($requisition->reference_code, $issuance->reference_code);
        $this->assertSame(Requisition::STATUS_ACCEPTED, $requisition->fresh()->status);

        $values = app(OwwaTemplateExportService::class)->cellValuesForIssuance(
            $issuance->loadMissing(['office', 'department', 'item', 'requisition']),
            'Consumable/Issuances/Appendix 64 - RSMI.xls'
        );

        $this->assertSame($requisition->reference_code, $values['A12']);
        $this->assertStringContainsString($issuance->reference_code, (string) $values['G6']);
    }

    public function test_accept_and_issue_action_submits_requisition_line_ids(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Consumables']);
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        DB::table('acquisitions')->insert([
            'reference_code' => 'ACQ-FEAT-2',
            'item_id' => $item->id,
            'office_id' => $office->id,
            'quantity' => 50,
            'acquisition_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0200',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $line = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $this->actingAs($custodian);

        Livewire::test(ListRequisitions::class)
            ->callAction(TestAction::make('acceptAndIssue')->table($requisition), [
                'issuance_date' => '2026-06-07',
                'lines' => RequisitionIssuanceFormSchema::defaultLines($requisition->fresh(), false),
            ])
            ->assertNotified();

        $this->assertSame(1, Issuance::query()->where('requisition_id', $requisition->id)->count());
        $this->assertSame(Requisition::STATUS_ACCEPTED, $requisition->fresh()->status);
        $this->assertSame(2, $line->fresh()->quantity_issued);
    }

    public function test_custodian_reject_action_is_visible_on_pending_uc_requisition(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);
        /** @var User $custodian */
        $custodian = User::factory()->create(['role' => User::ROLE_SUPPLY_CUSTODIAN]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0099',
            'office_id' => $office->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $this->actingAs($custodian);

        Livewire::test(ListRequisitions::class)
            ->assertCanSeeTableRecords([$requisition])
            ->assertActionVisible(TestAction::make('custodianReject')->table($requisition));

        $this->assertTrue(CustodianRequisitionActions::canReject($requisition));
    }

    public function test_mixed_category_requisition_issues_to_separate_category_lists(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $office = Office::factory()->create();
        $department = \App\Models\Department::query()->create([
            'office_id' => $office->id,
            'name' => 'Operations',
            'code' => '01',
        ]);
        $consumableCategory = ItemCategory::factory()->create(['name' => 'Consumables']);
        $semiCategory = ItemCategory::factory()->create(['name' => 'Semi-Expendable']);
        $consumableItem = Item::factory()->create(['item_category_id' => $consumableCategory->id]);
        $semiItem = Item::factory()->create([
            'item_category_id' => $semiCategory->id,
            'property_class' => \App\Support\ItemPropertyClass::OfficeEquipment,
            'estimated_useful_life' => '5 yrs',
        ]);

        foreach ([$consumableItem, $semiItem] as $item) {
            DB::table('acquisitions')->insert([
                'reference_code' => 'ACQ-MIX-'.$item->id,
                'item_id' => $item->id,
                'office_id' => $office->id,
                'quantity' => 50,
                'acquisition_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /** @var User $uc */
        $uc = User::factory()->create([
            'role' => User::ROLE_UNIT_CONSOLIDATOR,
            'office_id' => $office->id,
        ]);

        /** @var User $custodian */
        $custodian = User::factory()->create([
            'role' => User::ROLE_SUPPLY_CUSTODIAN,
        ]);

        $requisition = Requisition::query()->create([
            'reference_code' => '2026-01-0300',
            'office_id' => $office->id,
            'department_id' => $department->id,
            'requested_by' => $uc->id,
            'status' => Requisition::STATUS_PENDING,
        ]);

        $consumableLine = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $consumableItem->id,
            'quantity' => 2,
        ]);

        $semiLine = RequisitionItem::query()->create([
            'requisition_id' => $requisition->id,
            'item_id' => $semiItem->id,
            'quantity' => 1,
        ]);

        $this->assertTrue($requisition->fresh()->hasMixedCategories());

        $result = app(RequisitionFulfillmentService::class)->issueLines(
            $requisition,
            $custodian,
            [
                [
                    'requisition_item_id' => $consumableLine->id,
                    'quantity_to_issue' => 2,
                ],
                [
                    'requisition_item_id' => $semiLine->id,
                    'quantity_to_issue' => 1,
                ],
            ],
            '2026-06-08',
        );

        $this->assertSame(2, $result['created']);
        $this->assertSame(
            ['Consumables' => 1, 'Semi-Expendable' => 1],
            $result['categories'],
        );

        $summary = RequisitionLineDisplay::formatIssuanceCategorySummary(2, $result['categories']);
        $this->assertStringContainsString('Consumables (1)', $summary);
        $this->assertStringContainsString('Semi-Expendable (1)', $summary);

        $this->assertSame(2, Issuance::query()->where('requisition_id', $requisition->id)->count());

        session(['active_item_category_id' => $consumableCategory->id]);
        $consumableIssuanceIds = IssuanceResource::getEloquentQuery()->pluck('id')->all();
        $this->assertCount(1, $consumableIssuanceIds);
        $this->assertSame(
            $consumableItem->id,
            Issuance::query()->find($consumableIssuanceIds[0])?->item_id,
        );

        session(['active_item_category_id' => $semiCategory->id]);
        $semiIssuanceIds = IssuanceResource::getEloquentQuery()->pluck('id')->all();
        $this->assertCount(1, $semiIssuanceIds);
        $this->assertSame(
            $semiItem->id,
            Issuance::query()->find($semiIssuanceIds[0])?->item_id,
        );
    }

    public function test_issuance_without_requisition_is_blocked(): void
    {
        $office = Office::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);

        $this->expectException(\InvalidArgumentException::class);

        Issuance::query()->create([
            'office_id' => $office->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'issuance_date' => now()->toDateString(),
        ]);
    }
}
